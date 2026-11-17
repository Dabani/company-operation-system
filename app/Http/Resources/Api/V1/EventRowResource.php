<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Hr\Attendance\AttendanceService;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRowResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'type' => $this->type?->code, 'type_id' => $this->type_id, 'status' => $this->status?->code, 'status_id' => $this->status_id,
            'timezone' => AttendanceService::tz($this->organization_id), 'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String(),
            'venue' => $this->venue, 'online' => (bool) $this->online_url, 'department' => $this->department?->name, 'organizer' => $this->organizer?->name, 'capacity' => $this->capacity,
            'registered' => (int) ($this->registered_count ?? 0), 'my_status' => $this->relationLoaded('registrations') ? $this->registrations->first()?->status?->code : null];
    }
}
