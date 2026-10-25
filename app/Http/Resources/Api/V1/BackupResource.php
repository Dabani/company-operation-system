<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class BackupResource extends JsonResource
{
    private function lv($v, $lang): ?array { return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null; }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        return [
            'id' => $this->id, 'backup_date' => $this->backup_date?->toDateString(), 'location' => $this->lv($this->location, $lang), 'result' => $this->lv($this->result, $lang),
            'verified_by' => $this->verifier?->name, 'verified_at' => $this->verified_at?->toIso8601String(), 'notes' => $this->notes,
            'location_label' => $this->location?->label($lang), 'result_label' => $this->result?->label($lang), 'verified_by_name' => $this->verifier?->name,
        ];
    }
}
