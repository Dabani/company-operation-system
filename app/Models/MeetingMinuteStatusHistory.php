<?php

namespace App\Models;


use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingMinuteStatusHistory extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'meeting_minute_status_history';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function to() { return $this->belongsTo(LookupValue::class, 'to_status_id'); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
