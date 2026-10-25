<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArchiveRecord;
use App\Models\DisposalBatch;
use App\Models\FileAsset;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\RecordRules;
use App\Services\References;
use App\Support\Lookup;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Retention-based disposal: propose -> second person approves -> execute (certificate). */
class DisposalController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['status.translations', 'proposer', 'decider', 'executor']; }

    private function row(DisposalBatch $b, $lang, bool $records = false): array
    {
        $r = [
            'id' => $b->id, 'reference' => $b->reference, 'status' => ['code' => $b->status->code, 'label' => $b->status->label($lang), 'color' => $b->status->color],
            'proposed_by' => $b->proposer?->name, 'note' => $b->note, 'decided_by' => $b->decider?->name, 'decided_at' => $b->decided_at?->toIso8601String(), 'decision_note' => $b->decision_note,
            'executed_by' => $b->executor?->name, 'executed_at' => $b->executed_at?->toIso8601String(), 'method' => $b->method, 'witness' => $b->witness,
            'records_count' => $b->records_count ?? $b->records()->count(), 'created_at' => $b->created_at->toIso8601String(), 'can_decide' => $b->status->code === 'pending' && $b->proposed_by !== request()->user()->id && $this->ctx->can('archive.approve_disposal'),
        ];
        if ($records) $r['records'] = $b->records()->with(['category.translations', 'format'])->get()->map(fn ($x) => ['id' => $x->id, 'reference' => $x->reference, 'title' => $x->title, 'index_code' => $x->index_code, 'category' => $x->category->label($lang), 'format' => $x->format->code, 'retention_until' => $x->retention_until?->toDateString()])->values();
        return $r;
    }

    /** Archived records whose retention has ended and that are not on legal hold. */
    public function eligible(Request $request)
    {
        $rows = ArchiveRecord::with(['category.translations', 'format', 'location'])->whereHas('status', fn ($s) => $s->where('code', 'archived'))
            ->whereDate('retention_until', '<=', today())->where('legal_hold', false)->orderBy('retention_until')->limit(500)->get();
        $lang = $request->user()->language_id;
        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'title' => $r->title, 'index_code' => $r->index_code,
            'category' => $r->category->label($lang), 'format' => $r->format->code, 'retention_until' => $r->retention_until->toDateString(), 'location' => $r->location?->code])]);
    }

    public function index(Request $request)
    {
        $rows = DisposalBatch::with($this->with())->withCount('records')->orderByDesc('created_at')->limit(100)->get();
        return response()->json(['data' => $rows->map(fn ($b) => $this->row($b, $request->user()->language_id))]);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(['data' => $this->row(DisposalBatch::with($this->with())->findOrFail($id), $request->user()->language_id, true)]);
    }

    public function propose(Request $request)
    {
        $d = $request->validate(['record_ids' => 'required|array|min:1|max:500', 'record_ids.*' => 'string', 'note' => 'nullable|string|max:500']);
        $ids = array_values(array_unique($d['record_ids']));
        $records = ArchiveRecord::with('status')->whereIn('id', $ids)->get();
        abort_unless($records->count() === count($ids), 422, __('api.record_not_eligible'));
        foreach ($records as $r) {
            abort_unless($r->status->code === 'archived' && !$r->legal_hold && $r->retention_until && $r->retention_until->lte(today()), 422, __('api.record_not_eligible'));
        }

        $b = DB::transaction(function () use ($records, $d, $request) {
            $b = DisposalBatch::create(['reference' => References::next($this->ctx->organizationId(), 'disposal', 'DSP', true), 'status_id' => Lookup::id('disposal_status', 'pending'), 'proposed_by' => $request->user()->id, 'note' => $d['note'] ?? null]);
            $b->records()->attach($records->pluck('id'));
            foreach ($records as $r) {
                $r->update(['status_id' => Lookup::id('record_status', 'disposal_pending')]);
                RecordRules::event($r, 'disposal_proposed', null, null, $b->reference);
            }
            return $b;
        });
        AuditLogger::log('disposal.proposed', $b, [], ['reference' => $b->reference, 'records' => (string) $records->count()]);
        $approvers = Notifier::usersWithPermission($this->ctx->organizationId(), 'archive.approve_disposal')->reject(fn ($u) => $u->id === $request->user()->id);
        Notifier::send($approvers, 'disposal_proposed', ['ref' => $b->reference, 'user' => $request->user()->name, 'count' => $records->count()], null, ['in_app', 'email'], '/archive/disposal', $this->ctx->organizationId());
        return response()->json(['data' => ['id' => $b->id, 'reference' => $b->reference]], 201);
    }

    private function decide(Request $request, string $id, string $decision)
    {
        $b = DisposalBatch::with($this->with())->findOrFail($id);
        abort_unless($b->status->code === 'pending', 422, __('api.disposal_not_pending'));
        abort_if($b->proposed_by === $request->user()->id, 403, __('api.disposal_self_approval'));          // four-eyes
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        DB::transaction(function () use ($b, $decision, $d, $request) {
            $b->update(['status_id' => Lookup::id('disposal_status', $decision), 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
            if ($decision === 'rejected') {          // records go back to the archive
                foreach ($b->records()->get() as $r) {
                    $r->update(['status_id' => Lookup::id('record_status', 'archived')]);
                    RecordRules::event($r, 'disposal_rejected', null, null, $b->reference);
                }
            }
        });
        AuditLogger::log("disposal.$decision", $b, [], ['reference' => $b->reference], $d['note'] ?? null);
        Notifier::send($b->proposer->loadMissing('language'), 'disposal_decided', ['ref' => $b->reference, 'user' => $request->user()->name, 'decision' => __('api.decision_' . ($decision === 'approved' ? 'approved' : 'rejected'))], null, ['in_app', 'email'], '/archive/disposal', $this->ctx->organizationId());
        return $this->show($request, $id);
    }

    public function approve(Request $request, string $id) { return $this->decide($request, $id, 'approved'); }
    public function reject(Request $request, string $id) { return $this->decide($request, $id, 'rejected'); }

    /** Carry out the disposal: records become "disposed" (the register entry stays as evidence); attached digital files are removed. */
    public function execute(Request $request, string $id)
    {
        $b = DisposalBatch::with($this->with())->findOrFail($id);
        abort_unless($b->status->code === 'approved', 422, __('api.disposal_not_approved'));
        $d = $request->validate(['method' => 'required|string|max:150', 'witness' => 'nullable|string|max:150']);
        $recordLinkType = Lookup::id('file_link_type', 'record');

        DB::transaction(function () use ($b, $d, $request, $recordLinkType) {
            foreach ($b->records()->with('status')->get() as $r) {
                abort_if($r->legal_hold, 422, __('api.record_hold'));                      // a hold placed after approval stops the execution
                $r->update(['status_id' => Lookup::id('record_status', 'disposed'), 'disposed_on' => today()]);
                RecordRules::event($r, 'disposed', null, null, $b->reference);
                FileAsset::whereHas('links', fn ($l) => $l->where('link_type_id', $recordLinkType)->where('link_id', $r->id))->get()->each->delete();   // content purged after the retention window
            }
            $b->update(['status_id' => Lookup::id('disposal_status', 'executed'), 'executed_by' => $request->user()->id, 'executed_at' => now(), 'method' => $d['method'], 'witness' => $d['witness'] ?? null]);
        });
        AuditLogger::log('disposal.executed', $b, [], ['reference' => $b->reference, 'method' => $d['method']]);
        return $this->show($request, $id);
    }

    public function certificate(Request $request, string $id)
    {
        $b = DisposalBatch::with($this->with())->findOrFail($id);
        abort_unless($b->status->code === 'executed', 422, __('api.disposal_not_approved'));
        app()->setLocale($request->user()->language?->code ?? config('ops.default_language'));

        $html = view('pdf.destruction', ['b' => $b, 'org' => $this->ctx->organization(), 'records' => $b->records()->with(['category.translations', 'format'])->get()])->render();
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
        AuditLogger::log('disposal.certificate_generated', $b, [], ['reference' => $b->reference]);
        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $b->reference . '.pdf"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
