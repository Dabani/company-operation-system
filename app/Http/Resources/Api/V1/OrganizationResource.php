<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address,
            'has_logo' => (bool) $this->logo_path,
            'status_id' => $this->status_id,
            'default_language_id' => $this->default_language_id,
            'timezone_id' => $this->timezone_id,
            'currency_id' => $this->currency_id,
            'date_format_id' => $this->date_format_id,
            'timezone' => $this->whenLoaded('timezone', fn () => $this->timezone->code),
            'currency' => $this->whenLoaded('currency', fn () => $this->currency->code),
            'date_format' => $this->whenLoaded('dateFormat', fn () => $this->dateFormat->code),
        ];
    }
}
