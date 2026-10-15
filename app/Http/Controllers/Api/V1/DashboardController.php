<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\DashboardWidget;
use App\Services\OrganizationContext;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Widgets configured for the user's roles (union, ordered by the highest-level role first). */
    public function index(OrganizationContext $ctx)
    {
        $rows = DB::table('role_dashboard_widgets as rw')
            ->join('roles as r', 'r.id', '=', 'rw.role_id')
            ->whereIn('rw.role_id', $ctx->roleIds())
            ->orderByDesc('r.level')->orderBy('rw.sort_order')
            ->get(['rw.widget_id', 'rw.width', 'rw.sort_order']);

        $widgets = DashboardWidget::with(['type', 'permission'])->whereIn('id', $rows->pluck('widget_id'))->where('is_active', true)->get()->keyBy('id');

        $out = [];
        foreach ($rows->unique('widget_id') as $row) {
            $w = $widgets[$row->widget_id] ?? null;
            if (!$w || ($w->permission && !$ctx->can($w->permission->code))) continue;
            $out[] = [
                'id' => $w->id, 'code' => $w->code, 'name_key' => $w->name_key, 'type' => $w->type->code,
                'width' => $row->width ?: $w->default_width, 'icon' => $w->icon, 'color' => $w->color,
                'data' => $this->data($w->code, $ctx),
            ];
        }
        return response()->json(['data' => $out]);
    }

    /** Data providers. Later bundles register their own keys here; unknown keys return null (widget shows "coming soon"). */
    private function data(string $code, OrganizationContext $ctx): mixed
    {
        $org = $ctx->organizationId();
        return match ($code) {
            'stat_users' => Membership::where('organization_id', $org)->count(),
            'stat_roles' => Role::count(),
            'stat_departments' => Department::count(),
            'stat_audit_today' => AuditLog::where('organization_id', $org)->whereDate('created_at', today())->count(),
            'chart_users_by_role' => Role::withCount('memberships')->orderByDesc('level')->get()
                ->map(fn ($r) => ['name' => $r->name, 'value' => $r->memberships_count, 'color' => $r->color ?: 'blue.6'])->values(),
            'chart_activity_7d' => collect(range(6, 0))->map(function ($d) use ($org) {
                $day = today()->subDays($d);
                return ['date' => $day->format('d M'), 'events' => AuditLog::where('organization_id', $org)->whereDate('created_at', $day)->count()];
            })->values(),
            'list_recent_activity' => AuditLog::with('user')->where('organization_id', $org)->latest('created_at')->limit(8)->get()
                ->map(fn ($l) => ['action' => $l->action, 'user' => $l->user?->name, 'at' => $l->created_at->toIso8601String()])->values(),
            default => null,
        };
    }
}
