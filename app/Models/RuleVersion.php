<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RuleVersion extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = [
        'effective_from' => 'date', 'effective_to' => 'date', 'priority' => 'integer', 'version' => 'integer',
        'signed_off_at' => 'datetime', 'submitted_at' => 'datetime', 'activated_at' => 'datetime',
    ];

    public function ruleSet() { return $this->belongsTo(RuleSet::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
    public function conditions() { return $this->hasMany(RuleCondition::class); }
    public function outcomes() { return $this->hasMany(RuleOutcome::class); }
    public function history() { return $this->hasMany(RuleActivation::class)->orderBy('created_at'); }
    public function isDraftLike(): bool { return in_array($this->status?->code, ['draft', 'impact_check'], true); }
}
