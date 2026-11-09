<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrPosition extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'hr_position';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_positions';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function department() { return $this->belongsTo(Department::class); }
    public function grade() { return $this->belongsTo(HrGrade::class, 'grade_id'); }
}
