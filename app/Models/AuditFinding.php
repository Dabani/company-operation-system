<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuditFinding extends Model
{
    use HasUlids;

    protected $table = 'audit_findings';
    protected $guarded = [];
    protected $casts = ['due_on' => 'date', 'closed_on' => 'date'];

    public function audit() { return $this->belongsTo(ComplianceAudit::class, 'audit_id'); }
    public function severity() { return $this->belongsTo(LookupValue::class, 'severity_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
}
