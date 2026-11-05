<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'amount' => $this->amount, 'currency_code' => $this->currency_code,
            'subject_type' => $this->subjectType?->code, 'subject_id' => $this->subject_id,
            'status' => $this->status?->code, 'current_step' => $this->current_step,
            'step_label' => $this->flow?->steps->firstWhere('step_order', $this->current_step)?->label,
            'steps_total' => $this->flow?->steps->count(), 'flow' => $this->flow?->name, 'used_default_flow' => $this->used_default_flow,
            'requester' => $this->requester?->name, 'requester_id' => $this->requester_id,
            'requester_comment' => $this->requester_comment,
            'submitted_at' => $this->submitted_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($a) => [
                'step_order' => $a->step_order, 'decision' => $a->decision?->code, 'actor' => $a->actor?->name,
                'on_behalf_of' => $a->onBehalfOf?->name, 'comment' => $a->comment, 'acted_at' => $a->acted_at?->toIso8601String(),
                'rule_version_number' => $a->rule_version_number,
            ])),
        ];
    }
}
