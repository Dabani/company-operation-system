<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HrGrade;
use App\Models\HrPosition;
use App\Models\HrProgressionCriterion;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Support\LookupRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Position and grade framework and career-progression criteria (Guide 5.2). Pay bands need hr.salary.view. */
class HrStructureController extends Controller
{
    private function org(): ?string { return app(OrganizationContext::class)->organizationId(); }
    private function salaryVisible(): bool { return app(OrganizationContext::class)->can('hr.salary.view'); }

    private function gradeShape(HrGrade $g): array
    {
        $o = ['id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'level' => $g->level, 'is_active' => $g->is_active];
        return $this->salaryVisible() ? $o + ['salary_min' => $g->salary_min, 'salary_max' => $g->salary_max, 'currency_code' => $g->currency_code] : $o;
    }

    private function positionShape(HrPosition $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'department_id' => $p->department_id, 'department' => $p->department?->name,
            'grade_id' => $p->grade_id, 'grade' => $p->grade?->name, 'description' => $p->description, 'is_active' => $p->is_active];
    }

    // ---- grades --------------------------------------------------------------------------------------------------------
    public function grades() { return ['data' => HrGrade::orderBy('level')->orderBy('name')->get()->map(fn ($g) => $this->gradeShape($g))]; }

    private function gradeData(Request $r, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $d = $r->validate([
            'name' => [$req, 'string', 'max:150'], 'level' => 'nullable|integer|min:0|max:99', 'is_active' => 'sometimes|boolean',
            'salary_min' => 'nullable|numeric|min:0|max:9999999999999', 'salary_max' => 'nullable|numeric|min:0|max:9999999999999|gte:salary_min',
            'currency_code' => ['nullable', LookupRule::code('currency')],
        ]);
        if (array_key_exists('level', $d) && $d['level'] === null) {
            unset($d['level']);                                            // the column default (1) applies
        }
        if (array_intersect(array_keys($d), ['salary_min', 'salary_max', 'currency_code']) && !$this->salaryVisible()) {
            abort(403, 'forbidden');
        }
        return $d;
    }

    public function storeGrade(Request $request)
    {
        $g = HrGrade::create($this->gradeData($request, true));
        AuditLogger::log('hr.grade_created', $g);
        return response()->json(['data' => $this->gradeShape($g->refresh())], 201);
    }

    public function updateGrade(Request $request, string $id)
    {
        $g = HrGrade::findOrFail($id);
        $g->update($this->gradeData($request, false));
        AuditLogger::log('hr.grade_updated', $g);
        return ['data' => $this->gradeShape($g->refresh())];
    }

    // ---- positions -----------------------------------------------------------------------------------------------------
    public function positions() { return ['data' => HrPosition::with(['department', 'grade'])->orderBy('title')->get()->map(fn ($p) => $this->positionShape($p))]; }

    private function positionData(Request $r, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return $r->validate([
            'title' => [$req, 'string', 'max:150'], 'description' => 'nullable|string|max:2000', 'is_active' => 'sometimes|boolean',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $this->org())],
            'grade_id' => ['nullable', Rule::exists('hr_grades', 'id')->where('organization_id', $this->org())],
        ]);
    }

    public function storePosition(Request $request)
    {
        $p = HrPosition::create($this->positionData($request, true));
        AuditLogger::log('hr.position_created', $p);
        return response()->json(['data' => $this->positionShape($p->refresh()->load(['department', 'grade']))], 201);
    }

    public function updatePosition(Request $request, string $id)
    {
        $p = HrPosition::findOrFail($id);
        $p->update($this->positionData($request, false));
        AuditLogger::log('hr.position_updated', $p);
        return ['data' => $this->positionShape($p->refresh()->load(['department', 'grade']))];
    }

    // ---- progression criteria ------------------------------------------------------------------------------------
    private function criterionShape(HrProgressionCriterion $c): array
    {
        return ['id' => $c->id, 'from_grade_id' => $c->from_grade_id, 'from_grade' => $c->fromGrade?->name, 'to_grade_id' => $c->to_grade_id, 'to_grade' => $c->toGrade?->name,
            'min_months_in_grade' => $c->min_months_in_grade, 'min_rating_level' => $c->min_rating_level, 'notes' => $c->notes];
    }

    public function criteria() { return ['data' => HrProgressionCriterion::with(['fromGrade', 'toGrade'])->get()->map(fn ($c) => $this->criterionShape($c))]; }

    public function storeCriterion(Request $request)
    {
        $org = $this->org();
        $d = $request->validate([
            'from_grade_id' => ['required', Rule::exists('hr_grades', 'id')->where('organization_id', $org)],
            'to_grade_id' => ['required', 'different:from_grade_id', Rule::exists('hr_grades', 'id')->where('organization_id', $org)],
            'min_months_in_grade' => 'required|integer|min:0|max:600', 'min_rating_level' => 'nullable|integer|min:1|max:5', 'notes' => 'nullable|string|max:2000',
        ]);
        if (HrGrade::findOrFail($d['to_grade_id'])->level <= HrGrade::findOrFail($d['from_grade_id'])->level) {
            throw ValidationException::withMessages(['to_grade_id' => [__('hr.criteria.must_be_higher')]]);
        }
        if (HrProgressionCriterion::where('from_grade_id', $d['from_grade_id'])->where('to_grade_id', $d['to_grade_id'])->exists()) {
            throw ValidationException::withMessages(['to_grade_id' => [__('hr.criteria.exists')]]);
        }
        $c = HrProgressionCriterion::create($d);
        AuditLogger::log('hr.criterion_created', $c);
        return response()->json(['data' => $this->criterionShape($c->load(['fromGrade', 'toGrade']))], 201);
    }

    public function destroyCriterion(string $id)
    {
        $c = HrProgressionCriterion::findOrFail($id);
        $c->delete();
        AuditLogger::log('hr.criterion_deleted', $c);
        return response()->noContent();
    }
}
