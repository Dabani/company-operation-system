<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2', 'current_step' => 'integer', 'used_default_flow' => 'boolean',
        'submitted_at' => 'datetime', 'step_started_at' => 'datetime', 'last_escalated_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function flow() { return $this->belongsTo(ApprovalFlow::class, 'flow_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function subjectType() { return $this->belongsTo(LookupValue::class, 'subject_type_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function actions() { return $this->hasMany(ApprovalAction::class, 'request_id')->orderBy('acted_at'); }
    public function ruleVersion() { return $this->belongsTo(RuleVersion::class, 'rule_version_id'); }

    public function isPending(): bool { return $this->status?->code === 'pending'; }
    public function currentStep(): ?ApprovalStep { return $this->flow->steps->firstWhere('step_order', $this->current_step); }
}
