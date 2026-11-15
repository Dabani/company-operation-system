<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** How to read one kind of attendance file (a device export, a spreadsheet layout). Shipped profiles are read-only starting points. */
class AttendanceImportProfile extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_import_profiles';
    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean', 'header_row' => 'integer'];

    public const COLUMNS = ['layout', 'sheet', 'header_row', 'identify_by', 'employee_column', 'timestamp_column', 'date_column', 'time_column', 'direction_column',
        'in_values', 'out_values', 'ignore_values', 'date_formats', 'device_timezone'];

    public function slots() { return $this->hasMany(AttendanceImportSlot::class, 'profile_id')->orderBy('position'); }

    /** The settings the mapper needs, as a plain array. */
    public function settings(): array { return $this->only(self::COLUMNS); }
}
