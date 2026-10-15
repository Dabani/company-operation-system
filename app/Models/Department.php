<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];   // controllers only pass validated data; organization_id is set by the tenant trait
    protected $casts = ['is_active' => 'boolean'];

    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
}
