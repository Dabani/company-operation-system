<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInvoiceRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'invoice_number' => $this->invoice_number, 'status' => $this->status?->code, 'status_id' => $this->status_id, 'supplier' => $this->supplier?->name,
            'supplier_id' => $this->supplier_id, 'order_id' => $this->order_id, 'order' => $this->order?->code, 'invoice_date' => $this->invoice_date?->toDateString(), 'due_date' => $this->due_date?->toDateString(),
            'currency_code' => $this->currency_code, 'total_amount' => $this->total_amount];
    }
}
