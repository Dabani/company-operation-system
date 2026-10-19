<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetEvent;
use App\Support\Lookup;

class AssetLog
{
    public static function event(Asset $a, string $type, ?string $old = null, ?string $new = null, ?string $note = null): void
    {
        AssetEvent::create([
            'asset_id' => $a->id, 'user_id' => optional(request()->user())->id, 'event_type_id' => Lookup::id('asset_event_type', $type),
            'old_ref' => $old, 'new_ref' => $new, 'note' => $note ? mb_substr($note, 0, 250) : null,
        ]);
    }
}
