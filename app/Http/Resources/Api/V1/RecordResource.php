<?php

namespace App\Http\Resources\Api\V1;

use App\Services\RecordRules;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    private function lv($v, $lang): ?array { return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null; }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        $today = today();
        return [
            'id' => $this->id, 'reference' => $this->reference, 'title' => $this->title,
            'format' => $this->lv($this->format, $lang), 'category' => $this->lv($this->category, $lang),
            'status' => $this->lv($this->status, $lang), 'classification' => $this->lv($this->classification, $lang),
            'department_id' => $this->department_id, 'department' => $this->department?->name,
            'custodian' => $this->custodian ? ['id' => $this->custodian->id, 'name' => $this->custodian->name] : null,
            'location_id' => $this->location_id, 'location' => $this->location ? $this->location->code . ' — ' . $this->location->name : null,
            'record_date' => $this->record_date?->toDateString(), 'client_name' => $this->client_name, 'project_name' => $this->project_name,
            'index_code' => $this->index_code, 'digital_name' => RecordRules::digitalName($this->resource),
            'became_inactive_on' => $this->became_inactive_on?->toDateString(), 'transfer_due_on' => $this->transfer_due_on?->toDateString(),
            'archived_on' => $this->archived_on?->toDateString(), 'retention_until' => $this->retention_until?->toDateString(), 'disposed_on' => $this->disposed_on?->toDateString(),
            'transfer_overdue' => $this->transfer_due_on ? $this->transfer_due_on->lt($today) : false,
            'disposal_due' => $this->status?->code === 'archived' && $this->retention_until && $this->retention_until->lte($today) && !$this->legal_hold,
            'legal_hold' => $this->legal_hold, 'hold_reason' => $this->hold_reason,
            'description' => $this->when($this->relationLoaded('events'), $this->description),
            'created_at' => $this->created_at?->toIso8601String(),
            // flat copies for export
            'format_label' => $this->format?->label($lang), 'category_label' => $this->category?->label($lang), 'status_label' => $this->status?->label($lang),
            'custodian_name' => $this->custodian?->name,
        ];
    }
}
