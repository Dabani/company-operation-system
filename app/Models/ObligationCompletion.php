<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObligationCompletion extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['due_on' => 'date', 'completed_on' => 'date', 'automatic' => 'boolean', 'created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class, 'completed_by'); }
}
