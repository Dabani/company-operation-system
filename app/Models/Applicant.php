<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'applicant';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_applicants';
    protected $guarded = [];
    protected $casts = ['phone' => 'encrypted', 'applied_on' => 'date:Y-m-d', 'retention_until' => 'date:Y-m-d', 'consent_recorded_at' => 'datetime', 'anonymized_at' => 'datetime'];
    protected $hidden = ['phone'];

    public function vacancy() { return $this->belongsTo(Vacancy::class, 'vacancy_id'); }
    public function stage() { return $this->belongsTo(LookupValue::class, 'stage_id'); }
    public function history() { return $this->hasMany(ApplicantStageHistory::class, 'applicant_id'); }
    public function interviews() { return $this->hasMany(Interview::class, 'applicant_id'); }
    public function references() { return $this->hasMany(ReferenceCheck::class, 'applicant_id'); }
    public function offers() { return $this->hasMany(Offer::class, 'applicant_id'); }
}
