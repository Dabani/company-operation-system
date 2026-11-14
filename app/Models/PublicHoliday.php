<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_public_holidays';
    protected $guarded = [];
    protected $casts = ['holiday_date' => 'date:Y-m-d'];

}
