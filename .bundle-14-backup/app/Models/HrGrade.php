<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrGrade extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'hr_grade';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_grades';
    protected $guarded = [];
    protected $casts = ['level' => 'integer', 'is_active' => 'boolean', 'salary_min' => 'decimal:2', 'salary_max' => 'decimal:2'];
}
