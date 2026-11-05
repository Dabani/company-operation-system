<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UserDepartmentAssignment extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_head' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function scopeLevel() { return $this->belongsTo(LookupValue::class, 'scope_level_id'); }
}
