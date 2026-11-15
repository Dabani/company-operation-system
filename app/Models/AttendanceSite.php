<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A place people may clock in from: a circle on the map. */
class AttendanceSite extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_sites';
    protected $guarded = [];
    protected $casts = ['radius_meters' => 'integer', 'is_active' => 'boolean'];
}
