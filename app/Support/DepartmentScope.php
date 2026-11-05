<?php

namespace App\Support;

use App\Models\Department;
use App\Models\LookupValue;
use App\Models\User;
use App\Models\UserDepartmentAssignment;
use App\Services\OrganizationContext;

/**
 * One place that answers "what may this user see?" (Guide 3.2).
 *   own          rows the user owns
 *   team         rows of the department(s) the user is assigned to
 *   department   those departments and all their sub-departments
 *   organization everything in the active organization
 * Combine with a Laravel Policy: $this->authorize(...) for the ability, DepartmentScope for the data boundary.
 */
class DepartmentScope
{
    public function __construct(public readonly string $userId, public readonly bool $organization, public readonly array $departmentIds) {}

    public static function resolve(User $user): self
    {
        $ctx = app(OrganizationContext::class);
        $orgId = $ctx->organizationId();
        $assignments = UserDepartmentAssignment::query()->where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->get();

        $codes = LookupValue::whereIn('id', $assignments->pluck('scope_level_id'))->pluck('code', 'id');
        $wide = $assignments->contains(fn ($a) => ($codes[$a->scope_level_id] ?? null) === 'organization')
            || ($ctx->membership()?->user_id === $user->id && $ctx->can('scope.organization'));

        $direct = $assignments->filter(fn ($a) => in_array($codes[$a->scope_level_id] ?? null, ['team', 'department'], true) && $a->department_id)->values();
        $down = $direct->filter(fn ($a) => $codes[$a->scope_level_id] === 'department')->pluck('department_id')->all();

        $ids = $direct->pluck('department_id')->all();
        if ($down && !$wide) {
            $parents = Department::query()->pluck('parent_id', 'id')->all();
            $ids = array_values(array_unique(array_merge($ids, self::descendants($down, $parents))));
        }
        return new self($user->id, $wide, array_values(array_unique($ids)));
    }

    /** Pure helper (unit-tested): all descendants of $roots given [id => parent_id]. */
    public static function descendants(array $roots, array $parentOf): array
    {
        $out = [];
        $frontier = $roots;
        while ($frontier) {
            $next = [];
            foreach ($parentOf as $id => $parent) {
                if ($parent !== null && in_array($parent, $frontier, true) && !in_array($id, $out, true) && !in_array($id, $roots, true)) {
                    $out[] = $id;
                    $next[] = $id;
                }
            }
            $frontier = $next;
        }
        return $out;
    }

    public function allows(?string $ownerId, ?string $departmentId): bool
    {
        return $this->organization
            || ($ownerId !== null && $ownerId === $this->userId)
            || ($departmentId !== null && in_array($departmentId, $this->departmentIds, true));
    }
}
