<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RfqRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'status' => $this->status?->code, 'status_id' => $this->status_id, 'requisition_id' => $this->requisition_id,
            'requisition' => $this->requisition?->code, 'closing_date' => $this->closing_date?->toDateString(), 'invited' => $this->quotes_count ?? null, 'currency_code' => $this->requisition?->currency_code];
    }
}
