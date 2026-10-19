<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Asset;
use App\Models\EquipmentMovement;
use App\Models\FileAsset;
use App\Models\FileDeletionRequest;
use App\Models\LookupValue;
use App\Services\FileAccess;
use App\Support\Lookup;
use App\Models\Role;
use App\Models\SupportCase;
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
            'stat_open_cases' => SupportCase::whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'closed', 'cancelled']))->count(),
            'stat_my_cases' => SupportCase::where('requester_id', request()->user()->id)->whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'closed', 'cancelled']))->count(),
            'chart_cases_by_status' => $this->casesByStatus(),
            'stat_files' => FileAccess::scopeVisible(FileAsset::query())->whereHas('status', fn ($q) => $q->where('code', 'active'))
                ->whereDoesntHave('links', fn ($l) => $l->whereIn('link_type_id', [Lookup::id('file_link_type', 'user_avatar'), Lookup::id('file_link_type', 'org_logo')]))->count(),
            'stat_pending_deletions' => FileDeletionRequest::whereHas('status', fn ($q) => $q->where('code', 'pending'))->count(),
            'stat_assets' => Asset::whereHas('status', fn ($q) => $q->where('code', '!=', 'retired'))->count(),
            'stat_overdue_equipment' => EquipmentMovement::whereHas('status', fn ($q) => $q->where('code', 'checked_out'))->whereDate('expected_return_date', '<', today())->count(),
            'stat_open_incidents' => SupportCase::whereHas('type', fn ($q) => $q->where('code', 'incident'))->whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'closed', 'cancelled']))->count(),
            'chart_assets_by_status' => $this->assetsByStatus(),
            default => null,
        };
    }

    private function casesByStatus()
    {
        $counts = SupportCase::selectRaw('status_id, count(*) as c')->groupBy('status_id')->pluck('c', 'status_id');
        $lang = request()->user()->language_id;
        return LookupValue::with('translations')->whereIn('id', $counts->keys())->orderBy('sort_order')->get()
            ->map(fn ($v) => ['name' => $v->label($lang), 'value' => (int) $counts[$v->id], 'color' => ($v->color ?: 'gray') . '.6'])->values();
    }

    private function assetsByStatus()
    {
        $counts = Asset::selectRaw('status_id, count(*) as c')->groupBy('status_id')->pluck('c', 'status_id');
        $lang = request()->user()->language_id;
        return LookupValue::with('translations')->whereIn('id', $counts->keys())->orderBy('sort_order')->get()
            ->map(fn ($v) => ['name' => $v->label($lang), 'value' => (int) $counts[$v->id], 'color' => ($v->color ?: 'gray') . '.6'])->values();
    }
}
