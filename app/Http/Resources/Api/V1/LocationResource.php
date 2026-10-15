<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent?->name),
            'location_type_id' => $this->location_type_id,
            'location_type' => $this->whenLoaded('type', fn () => $this->type->label(optional($request->user())->language_id)),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
