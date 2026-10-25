<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingAttendee extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['attended' => 'boolean', 'signed_at' => 'datetime'];

    public function session() { return $this->belongsTo(TrainingSession::class, 'session_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
