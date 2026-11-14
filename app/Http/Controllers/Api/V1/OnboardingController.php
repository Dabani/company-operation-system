<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingItem;
use App\Models\OnboardingTemplateItem;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Onboarding checklist template (HR) and each new employee's checklist, generated at conversion. */
class OnboardingController extends Controller
{
    private function ctx(): OrganizationContext { return app(OrganizationContext::class); }

    private function itemShape(OnboardingItem $i): array
    {
        return ['id' => $i->id, 'title' => $i->title, 'due_on' => $i->due_on?->toDateString(), 'assignee_user_id' => $i->assignee_user_id, 'done' => $i->done_at !== null, 'done_at' => $i->done_at?->toIso8601String()];
    }

    private function tplShape(OnboardingTemplateItem $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'offset_days' => $t->offset_days, 'sort_order' => $t->sort_order, 'is_active' => $t->is_active];
    }

    // ---- template -------------------------------------------------------------------------------------------------------
    public function template() { return ['data' => OnboardingTemplateItem::orderBy('sort_order')->get()->map(fn ($t) => $this->tplShape($t))]; }

    private function tplRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['title' => "$req|string|max:200", 'offset_days' => 'sometimes|integer|min:-30|max:365', 'sort_order' => 'sometimes|integer|min:0|max:9999', 'is_active' => 'sometimes|boolean'];
    }

    public function storeTemplate(Request $request)
    {
        $t = OnboardingTemplateItem::create($request->validate($this->tplRules(true)) + ['sort_order' => (int) OnboardingTemplateItem::max('sort_order') + 1]);
        AuditLogger::log('onboarding.template_created', $t);
        return response()->json(['data' => $this->tplShape($t->refresh())], 201);
    }

    public function updateTemplate(Request $request, string $id)
    {
        $t = OnboardingTemplateItem::findOrFail($id);
        $t->update($request->validate($this->tplRules(false)));
        AuditLogger::log('onboarding.template_updated', $t);
        return ['data' => $this->tplShape($t->refresh())];
    }

    public function destroyTemplate(string $id)
    {
        $t = OnboardingTemplateItem::findOrFail($id);
        $t->delete();
        AuditLogger::log('onboarding.template_deleted', $t);
        return response()->noContent();
    }

    // ---- an employee's checklist -----------------------------------------------------------------------------------------
    private function allowed(Request $r, Employee $e): bool
    {
        return $e->user_id === $r->user()->id || $this->ctx()->can('hr.recruitment.manage') || $this->ctx()->can('hr.history.view');
    }

    public function forEmployee(Request $request, string $id)
    {
        $e = Employee::query()->visibleTo($request->user())->find($id) ?? Employee::where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($e && $this->allowed($request, $e), 404);
        return ['data' => OnboardingItem::where('employee_id', $e->id)->orderBy('due_on')->orderBy('created_at')->get()->map(fn ($i) => $this->itemShape($i))];
    }

    public function complete(Request $request, string $id)
    {
        $i = OnboardingItem::with('employee')->findOrFail($id);
        $ok = $i->assignee_user_id === $request->user()->id || $i->employee?->user_id === $request->user()->id || $this->ctx()->can('hr.recruitment.manage');
        abort_unless($ok, 404);
        if (!$i->done_at) {
            $i->update(['done_at' => now(), 'done_by' => $request->user()->id]);
            AuditLogger::log('onboarding.item_done', $i);
        }
        return ['data' => $this->itemShape($i->refresh())];
    }

    public function updateItem(Request $request, string $id)
    {
        $i = OnboardingItem::findOrFail($id);
        $d = $request->validate(['title' => 'sometimes|string|max:200', 'due_on' => 'nullable|date_format:Y-m-d', 'assignee_user_id' => ['nullable', Rule::exists('users', 'id')]]);
        $i->update($d);
        return ['data' => $this->itemShape($i->refresh())];
    }
}
