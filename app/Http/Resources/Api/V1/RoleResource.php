<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'level' => $this->level,
            'color' => $this->color,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'users_count' => $this->whenCounted('memberships'),
            'permission_ids' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('id')),
            'widget_ids' => $this->whenLoaded('widgets', fn () => $this->widgets->pluck('id')),
        ];
    }
}
