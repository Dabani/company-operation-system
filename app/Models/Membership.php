<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasUlids;

    protected $table = 'organization_memberships';
    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function department() { return $this->belongsTo(Department::class)->withoutGlobalScopes(); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function roles() { return $this->belongsToMany(Role::class, 'membership_roles', 'membership_id', 'role_id')->withPivot('assigned_by')->withTimestamps(); }
}
