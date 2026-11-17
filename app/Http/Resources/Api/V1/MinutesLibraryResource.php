<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

/** One published (or superseded) version of minutes in the library. Never carries the text. */
class MinutesLibraryResource extends JsonResource
{
    public function toArray($request): array
    {
        $m = $this->minutes->meeting;
        return [
            'id' => $this->id, 'version_no' => $this->version_no, 'status' => $this->status->code, 'visibility' => $this->visibility?->code,
            'published_at' => $this->published_at?->toIso8601String(), 'correction_reason' => $this->correction_reason,
            'meeting' => ['id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'starts_at' => $m->starts_at->toIso8601String(), 'confidential' => $m->isConfidential()],
        ];
    }
}
