<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
}
