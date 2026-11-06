<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\OrganizationContext;

/** One answer to "what may this user do in this project?": manager > member > reviewer > viewer > none. */
class ProjectAccess
{
    public function __construct(private OrganizationContext $ctx) {}

    public function viewAll(): bool { return $this->ctx->can('project.view_all'); }

    public function level(User $u, Project $p): ?string
    {
        if ($p->owner_id === $u->id || $this->ctx->can('project.manage_all')) return 'manager';
        $role = ProjectMember::where('project_id', $p->id)->where('user_id', $u->id)->first()?->role?->code;
        if ($role) return $role;
        return Project::query()->whereKey($p->id)->visibleTo($u, $this->viewAll())->exists() ? 'viewer' : null;
    }

    public function find(User $u, string $projectId): array
    {
        $p = Project::with('status')->findOrFail($projectId);
        $level = $this->level($u, $p);
        abort_unless($level, 404);                                    // not visible: no existence leak
        return [$p, $level];
    }

    public static function canEdit(string $level): bool { return in_array($level, ['manager', 'member'], true); }
    public static function canComment(string $level): bool { return $level !== 'viewer'; }
    public static function isManager(string $level): bool { return $level === 'manager'; }
}
