<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class MeetingRowResource extends JsonResource
{
    public function toArray($request): array
    {
        $mine = $this->relationLoaded('invitations') ? $this->invitations->first() : null;
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'type' => $this->type?->code, 'type_id' => $this->type_id,
            'status' => $this->status?->code, 'confidential' => $this->confidentiality?->code === 'confidential',
            'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String(),
            'room' => $this->room?->name, 'venue' => $this->venue, 'department' => $this->department?->name,
            'organizer' => $this->organizer?->name, 'chair' => $this->chair?->name, 'agenda_status' => $this->agendaStatus?->code,
            'recurring' => $this->recurrence_id !== null, 'my_response' => $mine?->response?->code];
    }
}
