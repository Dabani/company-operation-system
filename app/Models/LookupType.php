<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LookupType extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean'];

    public function values() { return $this->hasMany(LookupValue::class); }
}
