<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'number' => $this->number, 'project_id' => $this->project_id, 'project' => $this->project?->code, 'client' => $this->client?->name,
            'status' => $this->status?->code, 'status_id' => $this->status_id, 'currency_code' => $this->currency_code,
            'issue_date' => $this->issue_date?->toDateString(), 'due_date' => $this->due_date?->toDateString(),
            'total' => $this->total, 'balance' => $this->balance, 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
