<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_settings';
    protected $guarded = [];
    protected $casts = [];

}
