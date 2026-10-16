<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class CaseResource extends JsonResource
{
    private bool $withDescription = false;

    public function withDescription(): static { $this->withDescription = true; return $this; }

    private function lv($v, $lang): ?array
    {
        return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null;
    }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->when($this->withDescription, $this->description),
            'type' => $this->lv($this->type, $lang),
            'status' => $this->lv($this->status, $lang),
            'priority' => $this->lv($this->priority, $lang),
            'requester' => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name, 'has_avatar' => (bool) $this->requester->avatar_path] : null,
            'assignee' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name, 'has_avatar' => (bool) $this->assignee->avatar_path] : null,
            'department' => $this->department?->name,
            'due_at' => $this->due_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // flat copies used by list export/search columns
            'type_label' => $this->type?->label($lang), 'status_label' => $this->status?->label($lang), 'priority_label' => $this->priority?->label($lang),
            'requester_name' => $this->requester?->name, 'assignee_name' => $this->assignee?->name,
        ];
    }
}
