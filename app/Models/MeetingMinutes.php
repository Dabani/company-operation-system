<?php

namespace App\Models;


use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingMinutes extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'meeting_minutes';
    protected $guarded = [];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function versions() { return $this->hasMany(MeetingMinuteVersion::class, 'minutes_id')->orderBy('version_no'); }
}
