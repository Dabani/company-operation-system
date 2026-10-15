<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class LookupValueResource extends JsonResource
{
    public function toArray($request): array
    {
        $labels = [];
        foreach ($this->translations as $t) {
            $labels[$t->language->code ?? $t->language_id] = $t->label;
        }
        return [
            'id' => $this->id,
            'type' => $this->whenLoaded('type', fn () => $this->type->code),
            'code' => $this->code,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'is_global' => $this->organization_id === null,
            'label' => $this->label(optional($request->user())->language_id),
            'labels' => $labels,
        ];
    }
}
