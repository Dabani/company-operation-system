<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'status' => $this->status?->code, 'status_id' => $this->status_id, 'supplier' => $this->supplier?->name, 'supplier_id' => $this->supplier_id,
            'requisition_id' => $this->requisition_id, 'requisition' => $this->requisition?->code, 'needed_by' => $this->needed_by?->toDateString(), 'currency_code' => $this->currency_code, 'total_amount' => $this->total_amount];
    }
}
