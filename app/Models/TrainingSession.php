<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TrainingSession extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'training_sessions';
    protected $guarded = [];
    protected $casts = ['session_date' => 'datetime', 'retain_until' => 'date', 'is_mandatory' => 'boolean'];

    public function topic() { return $this->belongsTo(LookupValue::class, 'topic_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function attendees() { return $this->hasMany(TrainingAttendee::class, 'session_id'); }
}
