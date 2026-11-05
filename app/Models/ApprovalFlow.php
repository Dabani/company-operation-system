<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApprovalFlow extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];

    public function steps() { return $this->hasMany(ApprovalStep::class, 'flow_id')->orderBy('step_order'); }
    public function subjectType() { return $this->belongsTo(LookupValue::class, 'subject_type_id'); }
}
