<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayPayslip extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_payslips';
    protected $guarded = [];
    protected $hidden = ['bank_account'];
    protected $casts = ['bank_account' => 'encrypted', 'proration_factor' => 'decimal:6', 'gross' => 'decimal:2', 'taxable_earnings' => 'decimal:2', 'pensionable_earnings' => 'decimal:2', 'employee_contributions' => 'decimal:2',
        'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_contributions' => 'decimal:2', 'employer_cost' => 'decimal:2', 'net_difference' => 'decimal:2'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function run() { return $this->belongsTo(PayRun::class, 'run_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function lines() { return $this->hasMany(PayPayslipLine::class, 'payslip_id')->orderBy('sort_order'); }
    public function flags() { return $this->hasMany(PayPayslipFlag::class, 'payslip_id'); }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen($m->run_id));
        }
    }
}
