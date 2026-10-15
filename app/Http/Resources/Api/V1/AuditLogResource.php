<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'user' => $this->user?->name,
            'outcome' => $this->outcome?->code,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'reason' => $this->reason,
            'changes' => $this->whenLoaded('changes', fn () => $this->changes->map(fn ($c) => [
                'field' => $c->field, 'old' => $c->old_value, 'new' => $c->new_value,
            ])),
        ];
    }
}
