<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone,
            'address' => $this->address, 'tax_number' => $this->tax_number, 'portal_enabled' => $this->portal_enabled,
            'status_id' => $this->status_id, 'status' => $this->whenLoaded('status', fn () => $this->status?->code),
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => $c->only(['id', 'name', 'email', 'phone', 'job_title', 'is_primary']))),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
