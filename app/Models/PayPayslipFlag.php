<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayPayslipFlag extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_payslip_flags';
    protected $guarded = [];
    public $timestamps = false;

    public function severity() { return $this->belongsTo(LookupValue::class, 'severity_id'); }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen(PayRunLock::runIdOfPayslip($m->payslip_id)));
        }
    }
}
