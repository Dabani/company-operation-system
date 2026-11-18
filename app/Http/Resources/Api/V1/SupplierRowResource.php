<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'country_code' => $this->country_code, 'category' => $this->category?->code, 'category_id' => $this->category_id,
            'status' => $this->status?->code, 'status_id' => $this->status_id, 'email' => $this->email, 'phone' => $this->phone, 'contact_name' => $this->contact_name];
    }
}
