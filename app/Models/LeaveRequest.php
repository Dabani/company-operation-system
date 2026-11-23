<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'leave_request';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_leave_requests';
    protected $guarded = [];
    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'start_half' => 'boolean', 'end_half' => 'boolean', 'days' => 'decimal:2',
        'decided_at' => \App\Casts\UtcDateTime::class, 'cancelled_at' => \App\Casts\UtcDateTime::class];

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function leaveType() { return $this->belongsTo(LookupValue::class, 'leave_type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function dayRows() { return $this->hasMany(LeaveRequestDay::class, 'request_id'); }
}
