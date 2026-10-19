<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccessGrantResource extends JsonResource
{
    private function lv($v, $lang): ?array { return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null; }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        return [
            'id' => $this->id,
            'user' => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
            'resource' => $this->lv($this->resource, $lang), 'level' => $this->lv($this->level, $lang),
            'justification' => $this->justification, 'granted_by' => $this->grantedBy?->name, 'granted_at' => $this->granted_at?->toIso8601String(),
            'expires_on' => $this->expires_on?->toDateString(), 'state' => $this->state(),
            'last_reviewed_at' => $this->last_reviewed_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(), 'revoked_by' => $this->revokedBy?->name, 'revocation_reason' => $this->revocation_reason,
            // flat copies for export
            'user_name' => $this->user->name, 'resource_label' => $this->resource?->label($lang), 'level_label' => $this->level?->label($lang), 'granted_by_name' => $this->grantedBy?->name,
        ];
    }
}
