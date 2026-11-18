<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'type' => $this->type?->code, 'type_id' => $this->procurement_type_id, 'status' => $this->status?->code, 'status_id' => $this->status_id,
            'department' => $this->department?->name, 'department_id' => $this->department_id, 'requester' => $this->requester?->name, 'needed_by' => $this->needed_by?->toDateString(),
            'currency_code' => $this->currency_code, 'total_amount' => $this->total_amount, 'over_budget' => (bool) $this->over_budget, 'split_suspected' => (bool) $this->split_suspected];
    }
}
