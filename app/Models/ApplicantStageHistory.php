<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApplicantStageHistory extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_applicant_stage_history';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];

    public function fromStage() { return $this->belongsTo(LookupValue::class, 'from_stage_id'); }
    public function toStage() { return $this->belongsTo(LookupValue::class, 'to_stage_id'); }
}
