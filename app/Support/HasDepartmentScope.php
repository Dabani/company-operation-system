<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Add to any model with a department column. Every list, detail, export and mutation endpoint must apply it:
 *   Model::query()->visibleTo($request->user())        // lists / exports
 *   Model::query()->visibleTo($user)->findOrFail($id)   // detail / mutation (404 outside scope, never 403 - no existence leak)
 */
trait HasDepartmentScope
{
    public static function ownerColumn(): string { return 'created_by'; }
    public static function departmentColumn(): string { return 'department_id'; }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $s = DepartmentScope::resolve($user);
        if ($s->organization) {
            return $q;
        }
        $table = $q->getModel()->getTable();
        return $q->where(function (Builder $w) use ($s, $table) {
            $w->where("$table." . static::ownerColumn(), $s->userId);
            if ($s->departmentIds) {
                $w->orWhereIn("$table." . static::departmentColumn(), $s->departmentIds);
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return DepartmentScope::resolve($user)->allows($this->{static::ownerColumn()}, $this->{static::departmentColumn()});
    }
}
