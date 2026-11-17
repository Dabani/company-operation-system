<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingAgendaItem extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_confidential' => 'boolean', 'position' => 'integer', 'duration_minutes' => 'integer'];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function presenter() { return $this->belongsTo(User::class, 'presenter_id'); }
    public function priority() { return $this->belongsTo(LookupValue::class, 'priority_id'); }
}
