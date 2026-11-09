<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrReminderLog extends Model
{
    use HasUlids;

    protected $table = 'hr_reminder_log';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['due_on' => 'date:Y-m-d', 'sent_at' => 'datetime'];
}
