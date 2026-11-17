<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingAction extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'meeting_action';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['due_on' => 'date', 'completed_at' => 'datetime', 'closed_at' => 'datetime', 'due_alerted_on' => 'date', 'overdue_alerted_on' => 'date', 'seq' => 'integer'];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function resolution() { return $this->belongsTo(MeetingResolution::class, 'resolution_id'); }
    public function agendaItem() { return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }
}
