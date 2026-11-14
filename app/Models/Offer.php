<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'offer';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_offers';
    protected $guarded = [];
    protected $casts = ['salary_amount' => 'encrypted', 'start_date' => 'date:Y-m-d', 'contract_end_on' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'responded_on' => 'date:Y-m-d', 'issued_at' => 'datetime'];
    protected $hidden = ['salary_amount'];

    public function applicant() { return $this->belongsTo(Applicant::class, 'applicant_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function position() { return $this->belongsTo(HrPosition::class, 'position_id'); }
    public function grade() { return $this->belongsTo(HrGrade::class, 'grade_id'); }
    public function department() { return $this->belongsTo(Department::class, 'department_id'); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
}
