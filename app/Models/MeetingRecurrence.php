<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingRecurrence extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['ends_on' => 'date', 'interval_count' => 'integer', 'occurrences' => 'integer'];

    public function frequency() { return $this->belongsTo(LookupValue::class, 'frequency_id'); }
    public function meetings() { return $this->hasMany(Meeting::class, 'recurrence_id')->orderBy('starts_at'); }
}
