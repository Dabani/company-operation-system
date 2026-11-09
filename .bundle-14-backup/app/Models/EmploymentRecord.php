<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One dated employment fact. Append-only: only the workflow fields below may change, rows are never deleted. */
class EmploymentRecord extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_employment_records';
    protected $guarded = [];
    protected $hidden = ['salary_amount'];
    protected $casts = [
        'effective_from' => 'date:Y-m-d', 'contract_end_on' => 'date:Y-m-d', 'probation_end_on' => 'date:Y-m-d',
        'salary_amount' => 'encrypted', 'applied_at' => 'datetime',
    ];

    private const MUTABLE = ['status_id', 'approval_request_id', 'applied_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $bad = array_diff(array_keys($m->getDirty()), self::MUTABLE);
            if ($bad) {
                throw new LogicException('Employment records are append-only: ' . implode(', ', $bad));
            }
        });
        static::deleting(fn () => throw new LogicException('Employment records are never deleted.'));
    }

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function changeType() { return $this->belongsTo(LookupValue::class, 'change_type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function position() { return $this->belongsTo(HrPosition::class, 'position_id'); }
    public function grade() { return $this->belongsTo(HrGrade::class, 'grade_id'); }
    public function department() { return $this->belongsTo(Department::class, 'department_id'); }
}
