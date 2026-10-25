<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class TrainingSessionResource extends JsonResource
{
    private function lv($v, $lang): ?array { return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null; }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        $a = $this->relationLoaded('attendees') ? $this->attendees : collect();
        $mine = $a->firstWhere('user_id', optional($request->user())->id);
        return [
            'id' => $this->id, 'title' => $this->title, 'topic' => $this->lv($this->topic, $lang), 'status' => $this->lv($this->status, $lang),
            'session_date' => $this->session_date?->toIso8601String(), 'duration_minutes' => $this->duration_minutes, 'trainer' => $this->trainer, 'venue' => $this->venue,
            'is_mandatory' => $this->is_mandatory, 'retain_until' => $this->retain_until?->toDateString(),
            'invited_count' => $a->count(), 'attended_count' => $a->where('attended', true)->count(),
            'my' => $mine ? ['attended' => $mine->attended, 'signed_at' => $mine->signed_at?->toIso8601String()] : null,
            'topic_label' => $this->topic?->label($lang), 'status_label' => $this->status?->label($lang),
        ];
    }
}
