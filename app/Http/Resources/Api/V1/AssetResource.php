<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    private function lv($v, $lang): ?array
    {
        return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null;
    }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        return [
            'id' => $this->id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'type' => $this->lv($this->type, $lang),
            'status' => $this->lv($this->status, $lang),
            'condition' => $this->lv($this->condition, $lang),
            'manufacturer' => $this->manufacturer, 'model' => $this->model, 'serial_number' => $this->serial_number,
            'purchase_date' => $this->purchase_date?->toDateString(), 'purchase_cost' => $this->purchase_cost,
            'warranty_expires_on' => $this->warranty_expires_on?->toDateString(),
            'warranty_expired' => $this->warranty_expires_on?->isPast() ?? false,
            'assignee' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'department_id' => $this->department_id, 'department' => $this->department?->name,
            'location_id' => $this->location_id, 'location' => $this->location ? $this->location->code . ' — ' . $this->location->name : null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            // flat copies for export
            'type_label' => $this->type?->label($lang), 'status_label' => $this->status?->label($lang), 'condition_label' => $this->condition?->label($lang),
            'assignee_name' => $this->assignee?->name,
        ];
    }
}
