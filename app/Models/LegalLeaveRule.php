<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LegalLeaveRule extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_legal_leave_rules';
    protected $guarded = [];

    public function parameter() { return $this->belongsTo(LegalParameter::class, 'legal_parameter_id'); }
    public function leaveType() { return $this->belongsTo(LookupValue::class, 'leave_type_id'); }
    public function sex() { return $this->belongsTo(LookupValue::class, 'sex_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
