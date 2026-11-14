<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ScheduleAssignment extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_schedule_assignments';
    protected $guarded = [];
    protected $casts = ['effective_from' => 'date:Y-m-d'];

    public function schedule() { return $this->belongsTo(WorkSchedule::class, 'schedule_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
