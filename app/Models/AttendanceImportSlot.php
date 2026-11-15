<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AttendanceImportSlot extends Model
{
    use HasUlids;

    protected $table = 'hr_attendance_import_slots';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['position' => 'integer'];
}
