<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Scorecard extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_scorecards';
    protected $guarded = [];
    protected $casts = ['score' => 'integer', 'submitted_at' => 'datetime'];

    public function interview() { return $this->belongsTo(Interview::class, 'interview_id'); }
    public function panelist() { return $this->belongsTo(Employee::class, 'panelist_employee_id'); }
    public function recommendation() { return $this->belongsTo(LookupValue::class, 'recommendation_id'); }
}
