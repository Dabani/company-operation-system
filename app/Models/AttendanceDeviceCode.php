<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The number a biometric device or badge reader uses for an employee. */
class AttendanceDeviceCode extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_device_codes';
    protected $guarded = [];

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
