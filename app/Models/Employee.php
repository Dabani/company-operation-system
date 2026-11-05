<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\HasDepartmentScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Canonical person record. A COPS login (user_id) is optional and one-to-one. */
class Employee extends Model
{
    use HasUlids, SoftDeletes, BelongsToOrganization, HasDepartmentScope;

    protected $table = 'hr_employees';
    protected $guarded = [];
    protected $hidden = ['national_id', 'bank_account'];   // never serialised by accident
    protected $casts = ['national_id' => 'encrypted', 'bank_account' => 'encrypted', 'hired_on' => 'date', 'ended_on' => 'date'];

    public static function ownerColumn(): string { return 'user_id'; }

    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function manager() { return $this->belongsTo(self::class, 'manager_employee_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function getFullNameAttribute(): string { return trim($this->first_name . ' ' . $this->last_name); }
}
