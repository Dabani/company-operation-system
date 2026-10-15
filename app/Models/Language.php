<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];
}
