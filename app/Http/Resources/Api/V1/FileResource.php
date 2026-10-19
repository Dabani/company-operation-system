<?php

namespace App\Http\Resources\Api\V1;

use App\Services\FileAccess;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    private function lv($v, $lang): ?array
    {
        return $v ? ['id' => $v->id, 'code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color] : null;
    }

    public function toArray($request): array
    {
        $lang = optional($request->user())->language_id;
        $previewable = in_array($this->mime_type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'version_count' => $this->version_count,
            'classification' => $this->lv($this->classification, $lang),
            'status' => $this->lv($this->status, $lang),
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null,
            'legal_hold' => $this->legal_hold,
            'previewable' => $previewable,
            'is_image' => str_starts_with((string) $this->mime_type, 'image/'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => FileAccess::abilities($this->resource),
            // flat copies for list export
            'classification_label' => $this->classification?->label($lang), 'status_label' => $this->status?->label($lang), 'owner_name' => $this->owner?->name,
        ];
    }
}
