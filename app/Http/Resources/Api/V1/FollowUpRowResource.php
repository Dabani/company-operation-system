<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps an already-built follow-up row so paginated lists keep the standard {data, links, meta} shape. */
class FollowUpRowResource extends JsonResource
{
    public function toArray($request): array { return $this->resource; }
}
