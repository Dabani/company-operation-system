<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AttendanceNetwork extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_networks';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

}
