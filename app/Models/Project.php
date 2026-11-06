<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\DepartmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUlids, SoftDeletes, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'budget_amount' => 'decimal:2'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function client() { return $this->belongsTo(Client::class); }
    public function members() { return $this->hasMany(ProjectMember::class); }
    public function departments() { return $this->belongsToMany(Department::class, 'project_departments'); }
    public function milestones() { return $this->hasMany(Milestone::class)->orderBy('due_date'); }
    public function tasks() { return $this->hasMany(Task::class); }

    /** Visible to: whole-org viewers, owner, members, and anyone whose department scope covers a linked department. */
    public function scopeVisibleTo(Builder $q, User $user, bool $viewAll): Builder
    {
        if ($viewAll) return $q;
        $s = DepartmentScope::resolve($user);
        return $q->where(function (Builder $w) use ($user, $s) {
            $w->where('projects.owner_id', $user->id)
              ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id));
            if ($s->organization) {
                $w->orWhereRaw('1 = 1');
            } elseif ($s->departmentIds) {
                $w->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $s->departmentIds));
            }
        });
    }
}
