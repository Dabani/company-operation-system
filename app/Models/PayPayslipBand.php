<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayPayslipBand extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_payslip_bands';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['lower' => 'decimal:2', 'upper' => 'decimal:2', 'rate' => 'decimal:6', 'part' => 'decimal:2', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen(PayRunLock::runIdOfLine($m->line_id)));
        }
    }
}
