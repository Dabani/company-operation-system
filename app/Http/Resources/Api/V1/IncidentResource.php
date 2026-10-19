<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

/** A SupportCase of type "incident" with its incident_details loaded. */
class IncidentResource extends JsonResource
{
    private function lv($v, $lang): ?array
    {
        return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null;
    }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        $i = $this->incident;
        $open = !in_array($this->status->code, ['resolved', 'closed', 'cancelled'], true);
        return [
            'id' => $this->id, 'reference' => $this->reference, 'title' => $this->title,
            'status' => $this->lv($this->status, $lang), 'priority' => $this->lv($this->priority, $lang),
            'severity' => $this->lv($i?->severity, $lang),
            'requester' => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null,
            'detected_at' => $i?->detected_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
            'containment_due_at' => $i?->containment_due_at?->toIso8601String(), 'contained_at' => $i?->contained_at?->toIso8601String(),
            'rca_due_at' => $i?->rca_due_at?->toIso8601String(), 'rca_completed_at' => $i?->rca_completed_at?->toIso8601String(),
            'containment_overdue' => $open && $i && $i->containmentOverdue(), 'rca_overdue' => $open && $i && $i->rcaOverdue(),
            'personal_data_breach' => (bool) $i?->personal_data_breach,
            // flat copies for export
            'status_label' => $this->status->label($lang), 'severity_label' => $i?->severity?->label($lang), 'requester_name' => $this->requester?->name,
        ];
    }
}
