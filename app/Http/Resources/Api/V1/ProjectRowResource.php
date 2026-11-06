<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'status' => $this->status?->code, 'status_id' => $this->status_id,
            'owner' => $this->owner?->name, 'client' => $this->client?->name,
            'start_date' => $this->start_date?->toDateString(), 'end_date' => $this->end_date?->toDateString()];
    }
}
