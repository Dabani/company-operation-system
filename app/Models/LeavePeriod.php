<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LeavePeriod extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_leave_periods';
    protected $guarded = [];
    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'is_active' => 'boolean'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function leaveType() { return $this->belongsTo(LookupValue::class, 'leave_type_id'); }
}
