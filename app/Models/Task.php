<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasUlids, SoftDeletes, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime', 'number' => 'integer', 'position' => 'integer', 'estimate_minutes' => 'integer'];

    public function project() { return $this->belongsTo(Project::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function priority() { return $this->belongsTo(LookupValue::class, 'priority_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
    public function reporter() { return $this->belongsTo(User::class, 'reporter_id'); }
    public function milestone() { return $this->belongsTo(Milestone::class); }
    public function comments() { return $this->hasMany(TaskComment::class)->orderBy('created_at'); }
    public function history() { return $this->hasMany(TaskStatusHistory::class)->orderBy('created_at'); }
    public function blockedBy() { return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id'); }
}
