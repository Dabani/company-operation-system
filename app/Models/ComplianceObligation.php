<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ComplianceObligation extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'compliance_obligations';
    protected $guarded = [];
    protected $casts = ['next_due_on' => 'date', 'last_completed_on' => 'date', 'alerted_for' => 'date', 'is_active' => 'boolean'];

    public function frequency() { return $this->belongsTo(LookupValue::class, 'frequency_id'); }
    public function role() { return $this->belongsTo(Role::class, 'responsible_role_id')->withoutGlobalScopes(); }
    public function responsible() { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function legalReference() { return $this->belongsTo(LegalReference::class); }
    public function completions() { return $this->hasMany(ObligationCompletion::class, 'obligation_id'); }
}
