<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LookupType;
use App\Models\MeetingAgendaItem;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MeetingService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agenda items and the draft -> circulated -> approved path (Guide 6.3). The organizer/secretary edit; only the chair approves. */
class MeetingAgendaController extends Controller
{
    public function __construct(private MeetingAccess $access, private MeetingService $svc) {}

    private function rules(bool $create): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        $req = $create ? 'required' : 'sometimes';
        return [
            'title' => [$req, 'string', 'max:200'], 'description' => 'nullable|string|max:20000',
            'presenter_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)], 'presenter_name' => 'nullable|string|max:150',
            'duration_minutes' => 'nullable|integer|min:1|max:1440', 'expected_decision' => 'nullable|string|max:5000', 'is_confidential' => 'sometimes|boolean',
            'priority_id' => ['sometimes', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'agenda_priority')->pluck('id')))],
        ];
    }

    private function shape(MeetingAgendaItem $i): array
    {
        $i->loadMissing(['presenter', 'priority']);
        return ['id' => $i->id, 'position' => $i->position, 'title' => $i->title, 'description' => $i->description, 'presenter' => $i->presenter ? ['id' => $i->presenter->id, 'name' => $i->presenter->name] : null,
            'presenter_name' => $i->presenter_name, 'duration_minutes' => $i->duration_minutes, 'priority' => $i->priority?->code, 'expected_decision' => $i->expected_decision, 'is_confidential' => $i->is_confidential];
    }

    public function store(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        $item = $this->svc->addAgendaItem($m, $request->validate($this->rules(true)), $request->user());
        return response()->json(['data' => $this->shape($item)], 201);
    }

    public function update(Request $request, string $itemId)
    {
        $item = MeetingAgendaItem::findOrFail($itemId);
        $m = $this->access->find($request->user(), $item->meeting_id);
        $this->access->requireManage($request->user(), $m);
        return ['data' => $this->shape($this->svc->updateAgendaItem($item, $request->validate($this->rules(false)), $request->user()))];
    }

    public function destroy(Request $request, string $itemId)
    {
        $item = MeetingAgendaItem::findOrFail($itemId);
        $m = $this->access->find($request->user(), $item->meeting_id);
        $this->access->requireManage($request->user(), $m);
        $this->svc->removeAgendaItem($item, $request->user());
        return response()->noContent();
    }

    public function reorder(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        $d = $request->validate(['order' => 'required|array|min:1|max:200', 'order.*' => 'required|string']);
        $this->svc->reorderAgenda($m, $d['order'], $request->user());
        return ['data' => ['ok' => true]];
    }

    public function circulate(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        return ['data' => ['agenda_status' => $this->svc->circulateAgenda($m, $request->user())->agendaStatus->code]];
    }

    public function approve(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        abort_unless($this->access->canChair($request->user(), $m), 403, 'forbidden');
        return ['data' => ['agenda_status' => $this->svc->approveAgenda($m, $request->user())->agendaStatus->code]];
    }
}
