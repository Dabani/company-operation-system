<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LeaveTypeSetting extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_leave_type_settings';
    protected $guarded = [];
    protected $casts = ['uses_balance' => 'boolean', 'accrues' => 'boolean', 'is_paid' => 'boolean', 'requires_document' => 'boolean', 'allow_retroactive' => 'boolean',
        'ignores_blackout' => 'boolean', 'allow_half_days' => 'boolean', 'is_requestable' => 'boolean', 'is_active' => 'boolean'];

    public function type() { return $this->belongsTo(LookupValue::class, 'leave_type_id'); }
    public function dayBasis() { return $this->belongsTo(LookupValue::class, 'day_basis_id'); }
}
