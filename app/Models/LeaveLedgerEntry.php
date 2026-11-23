<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One line of a leave balance. Inserted, never changed or removed. */
class LeaveLedgerEntry extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'hr_leave_ledger';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['entry_date' => 'date:Y-m-d', 'days' => 'decimal:2', 'created_at' => \App\Casts\UtcDateTime::class];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at ??= now());
    }

    public function leaveType() { return $this->belongsTo(LookupValue::class, 'leave_type_id'); }
    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
