<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function role() { return $this->belongsTo(LookupValue::class, 'project_role_id'); }
}
