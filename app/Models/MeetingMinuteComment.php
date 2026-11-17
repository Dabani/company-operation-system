<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingMinuteComment extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => UtcDateTime::class, 'resolved_at' => UtcDateTime::class];

    public function user() { return $this->belongsTo(User::class); }
    public function version() { return $this->belongsTo(MeetingMinuteVersion::class, 'version_id'); }
}
