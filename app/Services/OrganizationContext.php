<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

/** Request-scoped tenant + authorization context. Derived from authenticated membership only. */
class OrganizationContext
{
    protected ?Organization $organization = null;
    protected ?Membership $membership = null;
    protected array $permissions = [];
    protected int $level = 0;
    protected array $roleIds = [];

    public function resolve(User $user, ?string $requestedOrgId = null): bool
    {
        $q = Membership::query()
            ->where('user_id', $user->id)
            ->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('organization.status', fn ($s) => $s->where('code', 'active'));

        $membership = $requestedOrgId
            ? (clone $q)->where('organization_id', $requestedOrgId)->first()
            : (clone $q)->orderByDesc('is_default')->orderBy('created_at')->first();

        if (!$membership) {
            return false;
        }

        $membership->load(['organization', 'roles.permissions']);
        $roles = $membership->roles->where('is_active', true);

        $this->membership = $membership;
        $this->organization = $membership->organization;
        $this->roleIds = $roles->pluck('id')->all();
        $this->level = (int) $roles->max('level');
        $this->permissions = $roles->flatMap(fn ($r) => $r->permissions->pluck('code'))->unique()->values()->all();

        return true;
    }

    public function resolved(): bool { return $this->organization !== null; }
    public function organization(): ?Organization { return $this->organization; }
    public function organizationId(): ?string { return $this->organization?->id; }
    public function membership(): ?Membership { return $this->membership; }
    public function permissions(): array { return $this->permissions; }
    public function roleIds(): array { return $this->roleIds; }
    public function level(): int { return $this->level; }
    public function can(string $code): bool { return in_array($code, $this->permissions, true); }
}
