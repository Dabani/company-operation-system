<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ComplianceAudit extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'compliance_audits';
    protected $guarded = [];
    protected $casts = ['started_on' => 'date', 'completed_on' => 'date', 'reported_at' => 'datetime'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function lead() { return $this->belongsTo(User::class, 'lead_auditor_id'); }
    public function findings() { return $this->hasMany(AuditFinding::class, 'audit_id'); }
}
