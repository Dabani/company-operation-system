<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingMinuteVersion extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['submitted_at' => UtcDateTime::class, 'approved_at' => UtcDateTime::class, 'published_at' => UtcDateTime::class, 'version_no' => 'integer', 'revision' => 'integer'];

    public function minutes() { return $this->belongsTo(MeetingMinutes::class, 'minutes_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function visibility() { return $this->belongsTo(LookupValue::class, 'visibility_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function publisher() { return $this->belongsTo(User::class, 'published_by'); }
    public function file() { return $this->belongsTo(FileAsset::class, 'file_id'); }
    public function reviewers() { return $this->hasMany(MeetingMinuteReviewer::class, 'version_id'); }
    public function recipients() { return $this->hasMany(MeetingMinuteRecipient::class, 'version_id'); }
    public function comments() { return $this->hasMany(MeetingMinuteComment::class, 'version_id')->orderBy('created_at'); }
    public function history() { return $this->hasMany(MeetingMinuteStatusHistory::class, 'version_id')->orderBy('created_at'); }
}
