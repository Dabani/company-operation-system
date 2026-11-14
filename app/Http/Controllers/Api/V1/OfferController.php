<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Offer;
use App\Services\AuditLogger;
use App\Services\Hr\OfferPdf;
use App\Services\Hr\RecruitmentService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use App\Support\LookupRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Offers: create (salary needs hr.salary.view), issue, record the answer, withdraw, letter PDF in four languages. */
class OfferController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }

    private function shape(Offer $o): array
    {
        $salary = $this->ctx()->can('hr.salary.view');
        return ['id' => $o->id, 'code' => $o->code, 'applicant_id' => $o->applicant_id, 'applicant' => trim($o->applicant?->first_name . ' ' . $o->applicant?->last_name), 'status' => $o->status?->code,
            'position_id' => $o->position_id, 'position' => $o->position?->title, 'grade_id' => $o->grade_id, 'department_id' => $o->department_id, 'category_id' => $o->category_id,
            'contract_type_id' => $o->contract_type_id, 'start_date' => $o->start_date->toDateString(), 'probation_months' => $o->probation_months, 'contract_end_on' => $o->contract_end_on?->toDateString(),
            'valid_until' => $o->valid_until?->toDateString(), 'issued_at' => $o->issued_at?->toIso8601String(), 'responded_on' => $o->responded_on?->toDateString(), 'terms' => $o->terms,
            'band_exception' => $o->band_exception_reason !== null] + ($salary ? ['salary_amount' => $o->salary_amount, 'salary_currency' => $o->salary_currency, 'salary_period' => $o->salary_period, 'band_exception_reason' => $o->band_exception_reason] : []);
    }

    private function load(string $id): Offer { return Offer::with(['applicant', 'status', 'position'])->findOrFail($id); }

    public function index(Request $request)
    {
        $q = Offer::with(['applicant', 'status', 'position'])->orderByDesc('created_at');
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id('offer_status', $s));
        }
        return ['data' => $q->limit(300)->get()->map(fn ($o) => $this->shape($o))];
    }

    public function store(Request $request, string $applicantId, RecruitmentService $svc)
    {
        abort_unless($this->ctx()->can('hr.salary.view'), 403, 'forbidden');
        $a = Applicant::findOrFail($applicantId);
        $org = $this->ctx()->organizationId();
        $exists = fn (string $t) => ['nullable', Rule::exists($t, 'id')->where('organization_id', $org)];
        $d = $request->validate([
            'category_id' => ['required', Rule::exists('lookup_values', 'id')], 'contract_type_id' => ['nullable', Rule::exists('lookup_values', 'id')],
            'position_id' => $exists('hr_positions'), 'grade_id' => $exists('hr_grades'), 'department_id' => $exists('departments'),
            'start_date' => 'required|date_format:Y-m-d', 'probation_months' => 'nullable|integer|min:0|max:24', 'contract_end_on' => 'nullable|date_format:Y-m-d|after:start_date',
            'salary_amount' => 'required|numeric|gt:0|max:9999999999999', 'salary_currency' => ['required', LookupRule::code('currency')], 'salary_period' => ['nullable', Rule::in(['monthly', 'annual'])],
            'terms' => 'nullable|string|max:10000', 'band_exception_reason' => 'nullable|string|max:1000',
        ]);
        $o = $svc->createOffer($a, $d, $request->user());
        return response()->json(['data' => $this->shape($this->load($o->id))], 201);
    }

    public function issue(Request $request, string $id, RecruitmentService $svc)
    {
        $o = $this->load($id);
        $d = $request->validate(['valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:today']);
        $svc->issueOffer($o, $d['valid_until'] ?? null);
        return ['data' => $this->shape($this->load($id))];
    }

    public function respond(Request $request, string $id, RecruitmentService $svc)
    {
        $o = $this->load($id);
        $d = $request->validate(['decision' => ['required', Rule::in(['accepted', 'declined'])], 'responded_on' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $svc->respond($o, $d['decision'], $d['responded_on'], $request->user());
        return ['data' => $this->shape($this->load($id))];
    }

    public function withdraw(Request $request, string $id, RecruitmentService $svc)
    {
        $o = $this->load($id);
        $svc->withdrawOffer($o, $request->user());
        return ['data' => $this->shape($this->load($id))];
    }

    /** The letter shows the salary, so it needs the salary permission; the download is audited. */
    public function pdf(Request $request, string $id)
    {
        abort_unless($this->ctx()->can('hr.salary.view'), 403, 'forbidden');
        $o = $this->load($id);
        $lang = $request->query('lang');
        $locale = in_array($lang, ['en', 'fr', 'rw', 'sw'], true) ? $lang : 'en';
        AuditLogger::log('offer.pdf_downloaded', $o, [], ['lang' => $locale]);
        return response((new OfferPdf())->render($o, $locale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$o->code}.pdf\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
