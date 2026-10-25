<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ArchiveRecord extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'records';
    protected $guarded = [];
    protected $casts = ['record_date' => 'date', 'became_inactive_on' => 'date', 'transfer_due_on' => 'date', 'archived_on' => 'date', 'retention_until' => 'date', 'disposed_on' => 'date',
        'legal_hold' => 'boolean', 'transfer_alerted_at' => 'datetime', 'retention_alerted_at' => 'datetime'];

    public function format() { return $this->belongsTo(LookupValue::class, 'format_id'); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function classification() { return $this->belongsTo(LookupValue::class, 'classification_id'); }
    public function department() { return $this->belongsTo(Department::class)->withoutGlobalScopes(); }
    public function custodian() { return $this->belongsTo(User::class, 'custodian_id'); }
    public function location() { return $this->belongsTo(Location::class)->withoutGlobalScopes(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function events() { return $this->hasMany(RecordEvent::class, 'record_id'); }
}
