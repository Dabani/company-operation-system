<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleDay extends Model
{
    protected $table = 'hr_schedule_days';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $casts = ['weekday' => 'integer'];

    public function shift() { return $this->belongsTo(Shift::class, 'shift_id'); }
}
