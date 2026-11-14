<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_corrections';
    protected $guarded = [];
    protected $casts = ['work_date' => 'date:Y-m-d', 'requested_in_at' => UtcDateTime::class, 'requested_out_at' => UtcDateTime::class, 'decided_at' => UtcDateTime::class];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
}
