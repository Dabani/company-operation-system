<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupportCase extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'cases';
    protected $guarded = [];
    protected $casts = ['due_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];

    public function type() { return $this->belongsTo(LookupValue::class, 'type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function priority() { return $this->belongsTo(LookupValue::class, 'priority_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
    public function department() { return $this->belongsTo(Department::class)->withoutGlobalScopes(); }
    public function comments() { return $this->hasMany(CaseComment::class, 'case_id'); }
    public function events() { return $this->hasMany(CaseEvent::class, 'case_id'); }
    public function incident() { return $this->hasOne(IncidentDetail::class, 'case_id'); }
}
