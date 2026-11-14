<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_interviews';
    protected $guarded = [];
    protected $casts = ['scheduled_at' => 'datetime'];

    public function applicant() { return $this->belongsTo(Applicant::class, 'applicant_id'); }
    public function type() { return $this->belongsTo(LookupValue::class, 'type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function panel() { return $this->belongsToMany(Employee::class, 'hr_interview_panelists', 'interview_id', 'employee_id'); }
    public function scorecards() { return $this->hasMany(Scorecard::class, 'interview_id'); }
}
