<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayRun extends Model
{
    use HasGeneratedCode, HasUlids, BelongsToOrganization;
    protected static string $codeEntity = 'pay_run';

    protected $table = 'pay_runs';
    protected $guarded = [];
    protected $casts = ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'md_required' => 'boolean', 'proration_mode' => 'integer', 'earnings_decimals' => 'integer', 'paid_on' => 'date:Y-m-d', 'declared_on' => 'date:Y-m-d',
        'calculated_at' => \App\Casts\UtcDateTime::class, 'reviewed_at' => \App\Casts\UtcDateTime::class, 'finance_at' => \App\Casts\UtcDateTime::class, 'md_at' => \App\Casts\UtcDateTime::class, 'locked_at' => \App\Casts\UtcDateTime::class];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function currency() { return $this->belongsTo(LookupValue::class, 'currency_id'); }
    public function payslips() { return $this->hasMany(PayPayslip::class, 'run_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_run_id'); }
    public function preparer() { return $this->belongsTo(User::class, 'prepared_by'); }
}
