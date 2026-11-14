<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'vacancy';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_vacancies';
    protected $guarded = [];
    protected $casts = ['opened_on' => 'date:Y-m-d', 'closes_on' => 'date:Y-m-d'];

    public function requisition() { return $this->belongsTo(Requisition::class, 'requisition_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function applicants() { return $this->hasMany(Applicant::class, 'vacancy_id'); }
}
