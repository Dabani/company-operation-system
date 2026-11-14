<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_schedules';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function days() { return $this->hasMany(ScheduleDay::class, 'schedule_id'); }
}
