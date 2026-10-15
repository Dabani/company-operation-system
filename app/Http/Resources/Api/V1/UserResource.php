<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $m = $this->relationLoaded('memberships') ? $this->memberships->first() : null;
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'has_avatar' => (bool) $this->avatar_path,
            'language_id' => $this->language_id,
            'status_id' => $this->status_id,
            'status' => $this->whenLoaded('status', fn () => $this->status->code),
            'status_label' => $this->whenLoaded('status', fn () => $this->status->label($request->user()?->language_id)),
            'status_color' => $this->whenLoaded('status', fn () => $this->status->color),
            'department_id' => $m?->department_id,
            'department' => $m?->department?->name,
            'job_title' => $m?->job_title,
            'role_ids' => $m ? $m->roles->pluck('id') : [],
            'roles' => $m ? $m->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'color' => $r->color, 'level' => $r->level])->values() : [],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
