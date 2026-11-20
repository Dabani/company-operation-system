<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayPayslipLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_payslip_lines';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['base' => 'decimal:2', 'rate' => 'decimal:6', 'amount' => 'decimal:2', 'taxable' => 'boolean', 'pensionable' => 'boolean', 'decimals' => 'integer'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function bands() { return $this->hasMany(PayPayslipBand::class, 'line_id')->orderBy('sort_order'); }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen(PayRunLock::runIdOfPayslip($m->payslip_id)));
        }
    }
}
