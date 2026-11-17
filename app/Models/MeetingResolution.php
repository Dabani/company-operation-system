<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingResolution extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'resolution';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['due_on' => 'date', 'completed_at' => 'datetime', 'due_alerted_on' => 'date', 'overdue_alerted_on' => 'date', 'seq' => 'integer'];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function agendaItem() { return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function responsible() { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function department() { return $this->belongsTo(Department::class, 'responsible_department_id'); }
    public function task() { return $this->belongsTo(Task::class); }
    public function obligation() { return $this->belongsTo(ComplianceObligation::class, 'obligation_id'); }
    public function actions() { return $this->hasMany(MeetingAction::class, 'resolution_id'); }
}
