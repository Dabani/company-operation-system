<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AttendancePeriod extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_periods';
    protected $guarded = [];
    protected $casts = ['month' => 'date:Y-m-d', 'validated_at' => UtcDateTime::class, 'reopened_at' => UtcDateTime::class];

}
