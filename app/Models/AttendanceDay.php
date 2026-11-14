<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_days';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['work_date' => 'date:Y-m-d', 'first_in_at' => UtcDateTime::class, 'last_out_at' => UtcDateTime::class, 'computed_at' => UtcDateTime::class, 'is_open' => 'boolean'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function shift() { return $this->belongsTo(Shift::class, 'shift_id'); }
}
