<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentDetail extends Model
{
    protected $primaryKey = 'case_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'detected_at' => 'datetime', 'containment_due_at' => 'datetime', 'contained_at' => 'datetime', 'rca_due_at' => 'datetime', 'rca_completed_at' => 'datetime',
        'regulator_notified_at' => 'datetime', 'data_subjects_notified_at' => 'datetime', 'containment_alerted_at' => 'datetime', 'rca_alerted_at' => 'datetime',
        'follow_up_required' => 'boolean', 'personal_data_breach' => 'boolean',
    ];

    public function supportCase() { return $this->belongsTo(SupportCase::class, 'case_id'); }
    public function severity() { return $this->belongsTo(LookupValue::class, 'severity_id'); }
    public function asset() { return $this->belongsTo(Asset::class, 'affected_asset_id')->withoutGlobalScopes(); }

    public function containmentOverdue(): bool { return !$this->contained_at && $this->containment_due_at && $this->containment_due_at->isPast(); }
    public function rcaOverdue(): bool { return !$this->rca_completed_at && $this->rca_due_at && $this->rca_due_at->isPast(); }
}
