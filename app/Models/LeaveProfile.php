<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Date of birth, sex and contractual weekly hours: only what statutory leave rules need. Encrypted, never in lists, every read audited. */
class LeaveProfile extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_leave_profiles';
    protected $guarded = [];
    protected $hidden = ['date_of_birth'];
    protected $casts = ['date_of_birth' => 'encrypted', 'weekly_hours' => 'decimal:2'];

    public function sex() { return $this->belongsTo(LookupValue::class, 'sex_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
