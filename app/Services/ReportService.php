<?php

namespace App\Services;

use App\Models\ArchiveAccessLog;
use App\Models\ArchiveRecord;
use App\Models\Asset;
use App\Models\AuditFinding;
use App\Models\BackupVerification;
use App\Models\CaseComment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceObligation;
use App\Models\EquipmentMovement;
use App\Models\LookupValue;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\ObligationCompletion;
use App\Models\SupportCase;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Bundle 7 - cross-module reports.
 *
 * Rules (developer guide, section 13.2):
 *  - every report is organization-scoped (all models used here carry the tenant global scope; child tables
 *    without an organization_id are reached only through a scoped parent) and permission-checked;
 *  - each report documents its formula, inclusion/exclusion, date basis, timezone and source tables
 *    (returned in `meta.method` and shown under "How is this calculated?");
 *  - aggregate output never contains incident details, internal case notes, record titles or personal data.
 *
 * Report text is localised on the server (lang/*\/reports.php) so exports carry translated headings.
 * Aggregation is done in PHP over capped, column-limited result sets (ROW_CAP) so it behaves the same on every database.
 */
class ReportService
{
    public const MAX_DAYS = 400;
    public const ROW_CAP = 20000;

    /** code => [module, icon, any-of permissions, snapshot?]. A snapshot report ignores the period (state "as of now"). */
    public static function definitions(): array
    {
        return [
            'executive' => ['management', 'IconPresentationAnalytics', ['report.receive'], false],
            'service_desk' => ['service_desk', 'IconHeadset', ['case.view_all'], false],
            'assets' => ['it', 'IconDeviceLaptop', ['asset.view'], true],
            'archive' => ['archive', 'IconFolders', ['archive.view'], false],
            'compliance' => ['compliance', 'IconShieldCheck', ['compliance.view'], false],
            'training' => ['compliance', 'IconSchool', ['training.manage', 'compliance.view'], false],
            'delivery' => ['system', 'IconSend', ['audit_log.view'], false],
        ];
    }

    public function __construct(private OrganizationContext $ctx) {}

    public function exists(string $code): bool { return isset(self::definitions()[$code]); }

    public function allowed(string $code): bool
    {
        $def = self::definitions()[$code] ?? null;
        if (!$def) return false;
        foreach ($def[2] as $perm) if ($this->ctx->can($perm)) return true;
        return false;
    }

    /** Reports the current user may open (the client groups them by module). */
    public function catalogue(): array
    {
        $out = [];
        foreach (self::definitions() as $code => [$module, $icon, , $snapshot]) {
            if (!$this->allowed($code)) continue;
            $out[] = ['code' => $code, 'module' => $module, 'module_label' => $this->t("module.$module"), 'icon' => $icon, 'snapshot' => $snapshot,
                'title' => $this->t("def.$code.title"), 'description' => $this->t("def.$code.description")];
        }
        return $out;
    }

    public function run(string $code, ?string $from, ?string $to): array
    {
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $toD = $to ? CarbonImmutable::parse($to, $tz)->startOfDay() : $today;
        $fromD = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $toD->subDays(89);
        if ($fromD->gt($toD)) [$fromD, $toD] = [$toD, $fromD];
        if ($fromD->diffInDays($toD) > self::MAX_DAYS) $fromD = $toD->subDays(self::MAX_DAYS);

        $p = [
            'tz' => $tz, 'today' => $today, 'from' => $fromD, 'to' => $toD,
            // Database timestamps are stored in the application timezone; convert the local period boundaries once.
            'fromDb' => $fromD->setTimezone(config('app.timezone')),
            'toDb' => $toD->endOfDay()->setTimezone(config('app.timezone')),
        ];

        $body = match ($code) {
            'executive' => $this->executive($p),
            'service_desk' => $this->serviceDesk($p),
            'assets' => $this->assets($p),
            'archive' => $this->archive($p),
            'compliance' => $this->compliance($p),
            'training' => $this->training($p),
            'delivery' => $this->delivery($p),
            default => abort(404),
        };

        return [
            'meta' => [
                'code' => $code,
                'title' => $this->t("def.$code.title"),
                'description' => $this->t("def.$code.description"),
                'from' => $fromD->toDateString(), 'to' => $toD->toDateString(),
                'snapshot' => self::definitions()[$code][3], 'timezone' => $tz, 'generated_at' => now()->toIso8601String(),
                'method' => [
                    'formula' => $this->t("def.$code.formula"), 'inclusion' => $this->t("def.$code.inclusion"),
                    'date_basis' => $this->t("def.$code.date_basis"), 'sources' => $this->t("def.$code.sources"),
                ],
            ],
        ] + $body;
    }

    /* ======================================================================== executive ===== */

    private function executive(array $p): array
    {
        $today = $p['today']->toDateString();
        $open = SupportCase::whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'closed', 'cancelled']));
        $openCases = (clone $open)->count();
        $overdueCases = (clone $open)->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $equipOverdue = $this->overdueEquipment($p);
        $transferDue = ArchiveRecord::whereHas('status', fn ($q) => $q->where('code', 'inactive'))->whereDate('transfer_due_on', '<', $today)->count();
        $disposalDue = ArchiveRecord::whereHas('status', fn ($q) => $q->where('code', 'archived'))->whereDate('retention_until', '<=', $today)->where('legal_hold', false)->count();
        $obligations = ComplianceObligation::where('is_active', true)->whereDate('next_due_on', '<', $today)->count();
        $findings = $this->openFindings()->count();
        $lastOk = $this->daysSinceLastOkBackup($p);
        $lostAssets = Asset::whereHas('status', fn ($q) => $q->whereIn('code', ['lost', 'stolen']))->count();

        $tone = fn (int|float $v, int $warnAt = 1, int $badAt = 5) => $v >= $badAt ? 'bad' : ($v >= $warnAt ? 'warn' : 'good');
        $backupTone = $lastOk === null ? 'bad' : ($lastOk > 14 ? 'bad' : ($lastOk > 7 ? 'warn' : 'good'));

        $kpis = [
            $this->kpi('open_cases', $openCases),
            $this->kpi('overdue_cases', $overdueCases, null, $tone($overdueCases)),
            $this->kpi('equipment_overdue', $equipOverdue, null, $tone($equipOverdue)),
            $this->kpi('disposal_due', $disposalDue, null, $tone($disposalDue, 1, 20)),
            $this->kpi('obligations_overdue', $obligations, null, $tone($obligations, 1, 3)),
            $this->kpi('open_findings', $findings, null, $tone($findings, 1, 10)),
            $this->kpi('days_since_backup', $lastOk, 'days', $backupTone),
            $this->kpi('lost_assets', $lostAssets, null, $tone($lostAssets, 1, 3)),
        ];

        $risk = [
            ['label' => $this->t('kpi.overdue_cases'), 'value' => $overdueCases],
            ['label' => $this->t('kpi.equipment_overdue'), 'value' => $equipOverdue],
            ['label' => $this->t('kpi.transfer_overdue'), 'value' => $transferDue],
            ['label' => $this->t('kpi.disposal_due'), 'value' => $disposalDue],
            ['label' => $this->t('kpi.obligations_overdue'), 'value' => $obligations],
            ['label' => $this->t('kpi.open_findings'), 'value' => $findings],
        ];

        [$trend] = $this->caseTrend($p);

        $rows = collect($kpis)->map(fn ($k) => [
            'indicator' => $k['label'],
            'value' => $k['value'] === null ? '—' : $k['value'] . ($k['unit'] ? ' ' . $this->unit($k['unit']) : ''),
            'status' => $this->t('status.' . $k['tone']),
        ])->all();

        return [
            'kpis' => $kpis,
            'charts' => [
                ['key' => 'risk', 'type' => 'bar', 'title' => $this->t('chart.attention_items'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'orange.6']], 'data' => $risk],
                $trend,
            ],
            'table' => $this->table(['indicator', 'value', 'status'], $rows),
        ];
    }

    /* ===================================================================== service desk ===== */

    private function serviceDesk(array $p): array
    {
        $created = SupportCase::whereBetween('created_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)
            ->get(['id', 'type_id', 'priority_id', 'requester_id', 'created_at', 'due_at', 'resolved_at']);
        $resolved = SupportCase::whereBetween('resolved_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)->get(['id', 'created_at', 'due_at', 'resolved_at']);
        $openQ = SupportCase::whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'closed', 'cancelled']));
        $overdue = (clone $openQ)->whereNotNull('due_at')->where('due_at', '<', now())->count();

        // First response = first public comment written by someone other than the requester.
        $first = [];
        if ($created->isNotEmpty()) {
            $byCase = $created->keyBy('id');
            CaseComment::whereIn('case_id', $byCase->keys())->where('is_internal', false)->orderBy('created_at')->get(['case_id', 'user_id', 'created_at'])
                ->each(function ($c) use (&$first, $byCase) {
                    if (isset($first[$c->case_id]) || $c->user_id === $byCase[$c->case_id]->requester_id) return;
                    $first[$c->case_id] = max(0, $byCase[$c->case_id]->created_at->diffInMinutes($c->created_at)) / 60;
                });
        }
        $medianFirst = $this->median(array_values($first));
        $avgRes = $resolved->isEmpty() ? null : round($resolved->avg(fn ($c) => max(0, $c->created_at->diffInMinutes($c->resolved_at)) / 60), 1);
        $withDue = $resolved->filter(fn ($c) => $c->due_at);
        $sla = $withDue->isEmpty() ? null : round(100 * $withDue->filter(fn ($c) => $c->resolved_at->lte($c->due_at))->count() / $withDue->count(), 1);

        $map = $this->lookupMap($created->pluck('type_id')->merge($created->pluck('priority_id')));
        $byType = $created->groupBy('type_id')->map(fn ($g, $id) => ['name' => $map[$id]['label'] ?? '—', 'value' => $g->count(), 'color' => $this->color($map[$id]['color'] ?? null)])->values()->all();
        $byPriority = $created->groupBy('priority_id')->map(fn ($g, $id) => ['label' => $map[$id]['label'] ?? '—', 'sort' => $map[$id]['sort'] ?? 0, 'value' => $g->count()])
            ->sortBy('sort')->values()->map(fn ($r) => Arr::except($r, 'sort'))->all();

        $load = (clone $openQ)->whereNotNull('assignee_id')->selectRaw('assignee_id, count(*) as c')->groupBy('assignee_id')->orderByDesc('c')->limit(10)->pluck('c', 'assignee_id');
        $names = User::whereIn('id', $load->keys())->pluck('name', 'id');
        $workload = $load->map(fn ($c, $id) => ['label' => $names[$id] ?? '—', 'value' => (int) $c])->values()->all();

        [$trend, $rows] = $this->caseTrend($p, $created, $resolved);

        return [
            'kpis' => [
                $this->kpi('opened', $created->count()),
                $this->kpi('resolved', $resolved->count()),
                $this->kpi('first_response_median', $medianFirst, 'hours'),
                $this->kpi('resolution_avg', $avgRes, 'hours'),
                $this->kpi('sla_met', $sla, 'percent', $sla === null ? null : ($sla >= 90 ? 'good' : ($sla >= 75 ? 'warn' : 'bad'))),
                $this->kpi('overdue_cases', $overdue, null, $overdue ? 'warn' : 'good'),
            ],
            'charts' => [
                $trend,
                ['key' => 'by_type', 'type' => 'donut', 'title' => $this->t('chart.by_type'), 'data' => $byType],
                ['key' => 'by_priority', 'type' => 'bar', 'title' => $this->t('chart.by_priority'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'brand.6']], 'data' => $byPriority],
                ['key' => 'workload', 'type' => 'bar', 'title' => $this->t('chart.workload'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.open'), 'color' => 'secondary.6']], 'data' => $workload],
            ],
            'table' => $this->table(['period', 'opened', 'resolved'], $rows),
        ];
    }

    /** @return array{0: array, 1: array} chart definition and table rows */
    private function caseTrend(array $p, ?Collection $created = null, ?Collection $resolved = null): array
    {
        $created ??= SupportCase::whereBetween('created_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)->get(['created_at']);
        $resolved ??= SupportCase::whereBetween('resolved_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)->get(['resolved_at']);
        $b = $this->buckets($p);
        $o = $this->countByBucket($created->pluck('created_at'), $b, $p);
        $r = $this->countByBucket($resolved->pluck('resolved_at'), $b, $p);
        $data = $rows = [];
        foreach ($b['keys'] as $k) {
            $label = $b['labels'][$k];
            $data[] = ['label' => $label, 'opened' => $o[$k], 'resolved' => $r[$k]];
            $rows[] = ['period' => $label, 'opened' => $o[$k], 'resolved' => $r[$k]];
        }
        return [[
            'key' => 'trend', 'type' => 'line', 'title' => $this->t('chart.opened_vs_resolved'), 'x' => 'label',
            'series' => [['name' => 'opened', 'label' => $this->t('col.opened'), 'color' => 'brand.6'], ['name' => 'resolved', 'label' => $this->t('col.resolved'), 'color' => 'secondary.6']],
            'data' => $data,
        ], $rows];
    }

    /* ============================================================================ assets ===== */

    private function assets(array $p): array
    {
        $assets = Asset::limit(self::ROW_CAP)->get(['id', 'asset_tag', 'name', 'type_id', 'status_id', 'purchase_date', 'purchase_cost', 'warranty_expires_on']);
        $map = $this->lookupMap($assets->pluck('type_id')->merge($assets->pluck('status_id')));
        $active = $assets->filter(fn ($a) => ($map[$a->status_id]['code'] ?? '') !== 'retired');
        $today = $p['today']->toDateString();
        $in90 = $p['today']->addDays(90)->toDateString();
        $expiring = $active->filter(fn ($a) => $a->warranty_expires_on && $a->warranty_expires_on->toDateString() >= $today && $a->warranty_expires_on->toDateString() <= $in90)
            ->sortBy(fn ($a) => $a->warranty_expires_on->toDateString());
        $expired = $active->filter(fn ($a) => $a->warranty_expires_on && $a->warranty_expires_on->toDateString() < $today)->count();
        $cost = round((float) $active->sum(fn ($a) => (float) $a->purchase_cost), 2);
        $currency = $this->ctx->organization()?->currency?->code;

        $byStatus = $assets->groupBy('status_id')->map(fn ($g, $id) => ['name' => $map[$id]['label'] ?? '—', 'value' => $g->count(), 'color' => $this->color($map[$id]['color'] ?? null)])->values()->all();
        $byType = $active->groupBy('type_id')->map(fn ($g, $id) => ['label' => $map[$id]['label'] ?? '—', 'value' => $g->count()])->sortByDesc('value')->values()->all();
        $byYear = $active->filter(fn ($a) => $a->purchase_date)->groupBy(fn ($a) => $a->purchase_date->format('Y'))
            ->map(fn ($g, $y) => ['label' => (string) $y, 'value' => $g->count()])->sortBy('label')->values()->all();

        $movements = EquipmentMovement::whereBetween('created_at', [$p['fromDb'], $p['toDb']])->count();
        $overdue = $this->overdueEquipment($p);
        $count = fn (string ...$codes) => $assets->filter(fn ($a) => in_array($map[$a->status_id]['code'] ?? '', $codes, true))->count();
        $lost = $count('lost', 'stolen');

        $rows = $expiring->take(100)->map(fn ($a) => [
            'asset_tag' => $a->asset_tag, 'name' => $a->name, 'type' => $map[$a->type_id]['label'] ?? '—', 'status' => $map[$a->status_id]['label'] ?? '—',
            'warranty_expires_on' => $a->warranty_expires_on->toDateString(),
            'days_left' => (int) round($p['today']->diffInDays(CarbonImmutable::parse($a->warranty_expires_on->toDateString(), $p['tz']), false)),
        ])->values()->all();

        return [
            'kpis' => [
                $this->kpi('assets_active', $active->count()),
                $this->kpi('assets_in_repair', $count('in_repair')),
                $this->kpi('lost_assets', $lost, null, $lost ? 'bad' : 'good'),
                $this->kpi('warranty_expiring', $expiring->count(), null, $expiring->count() ? 'warn' : 'good'),
                $this->kpi('warranty_expired', $expired),
                $this->kpi('equipment_overdue', $overdue, null, $overdue ? 'warn' : 'good'),
                $this->kpi('movements', $movements),
                $this->kpi('asset_value', $cost, $currency ?: null),
            ],
            'charts' => [
                ['key' => 'by_status', 'type' => 'donut', 'title' => $this->t('chart.by_status'), 'data' => $byStatus],
                ['key' => 'by_type', 'type' => 'bar', 'title' => $this->t('chart.by_type'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'brand.6']], 'data' => $byType],
                ['key' => 'by_year', 'type' => 'bar', 'title' => $this->t('chart.acquired_by_year'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'secondary.6']], 'data' => $byYear],
            ],
            'table' => $this->table(['asset_tag', 'name', 'type', 'status', 'warranty_expires_on', 'days_left'], $rows),
        ];
    }

    /* ========================================================================== archive ===== */

    private function archive(array $p): array
    {
        $recs = ArchiveRecord::limit(self::ROW_CAP)->get(['id', 'status_id', 'category_id', 'retention_until', 'transfer_due_on', 'legal_hold']);
        $map = $this->lookupMap($recs->pluck('status_id')->merge($recs->pluck('category_id')));
        $code = fn ($r) => $map[$r->status_id]['code'] ?? '';
        $today = $p['today']->toDateString();
        $live = $recs->reject(fn ($r) => $code($r) === 'disposed');

        $transferOverdue = $recs->filter(fn ($r) => $code($r) === 'inactive' && $r->transfer_due_on && $r->transfer_due_on->toDateString() < $today)->count();
        $disposalDue = $recs->filter(fn ($r) => $code($r) === 'archived' && !$r->legal_hold && $r->retention_until && $r->retention_until->toDateString() <= $today)->count();
        $holds = $live->filter(fn ($r) => $r->legal_hold)->count();

        $logs = ArchiveAccessLog::whereBetween('created_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)->get(['created_at']);
        $overdueReturns = ArchiveAccessLog::whereHas('status', fn ($q) => $q->where('code', 'authorized'))->whereNull('returned_at')->whereDate('due_back_on', '<', $today)->count();

        $b = $this->buckets($p);
        $perBucket = $this->countByBucket($logs->pluck('created_at'), $b, $p);
        $accessSeries = array_map(fn ($k) => ['label' => $b['labels'][$k], 'requests' => $perBucket[$k]], $b['keys']);

        $byStatus = $recs->groupBy('status_id')->map(fn ($g, $id) => ['name' => $map[$id]['label'] ?? '—', 'sort' => $map[$id]['sort'] ?? 0, 'value' => $g->count(), 'color' => $this->color($map[$id]['color'] ?? null)])
            ->sortBy('sort')->values()->map(fn ($r) => Arr::except($r, 'sort'))->all();
        $byCategory = $live->groupBy('category_id')->map(fn ($g, $id) => ['label' => $map[$id]['label'] ?? '—', 'value' => $g->count()])->sortByDesc('value')->values()->all();

        $year = (int) $p['today']->format('Y');
        $forecast = [];
        for ($y = $year; $y <= $year + 5; $y++) {
            $forecast[] = ['label' => (string) $y, 'value' => $live->filter(fn ($r) => $r->retention_until && (int) $r->retention_until->format('Y') === $y)->count()];
        }

        $statusCodes = ['active', 'inactive', 'archived', 'disposal_pending', 'disposed'];
        $rows = $recs->groupBy('category_id')->map(function ($g, $id) use ($map, $statusCodes, $code) {
            $row = ['category' => $map[$id]['label'] ?? '—'];
            foreach ($statusCodes as $s) $row[$s] = $g->filter(fn ($r) => $code($r) === $s)->count();
            $row['total'] = $g->count();
            return $row;
        })->sortByDesc('total')->values()->all();

        $n = fn (string $c) => $recs->filter(fn ($r) => $code($r) === $c)->count();
        return [
            'kpis' => [
                $this->kpi('records_live', $live->count()),
                $this->kpi('records_active', $n('active')),
                $this->kpi('records_archived', $n('archived')),
                $this->kpi('transfer_overdue', $transferOverdue, null, $transferOverdue ? 'warn' : 'good'),
                $this->kpi('disposal_due', $disposalDue, null, $disposalDue ? 'warn' : 'good'),
                $this->kpi('legal_holds', $holds),
                $this->kpi('access_requests', $logs->count()),
                $this->kpi('returns_overdue', $overdueReturns, null, $overdueReturns ? 'bad' : 'good'),
            ],
            'charts' => [
                ['key' => 'by_status', 'type' => 'donut', 'title' => $this->t('chart.by_status'), 'data' => $byStatus],
                ['key' => 'by_category', 'type' => 'bar', 'title' => $this->t('chart.by_category'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'brand.6']], 'data' => $byCategory],
                ['key' => 'access_trend', 'type' => 'line', 'title' => $this->t('chart.access_requests'), 'x' => 'label', 'series' => [['name' => 'requests', 'label' => $this->t('kpi.access_requests'), 'color' => 'secondary.6']], 'data' => $accessSeries],
                ['key' => 'retention_forecast', 'type' => 'bar', 'title' => $this->t('chart.retention_forecast'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'orange.6']], 'data' => $forecast],
            ],
            'table' => $this->table(['category', ...$statusCodes, 'total'], $rows),
        ];
    }

    /* ====================================================================== compliance ===== */

    private function compliance(array $p): array
    {
        $today = $p['today']->toDateString();
        $obligations = ComplianceObligation::with('frequency.translations')->where('is_active', true)->orderBy('next_due_on')->get();
        $overdue = $obligations->filter(fn ($o) => $o->next_due_on->toDateString() < $today)->count();

        $completions = ObligationCompletion::whereIn('obligation_id', ComplianceObligation::pluck('id'))
            ->whereBetween('completed_on', [$p['from']->toDateString(), $p['to']->toDateString()])->get(['due_on', 'completed_on']);
        $onTime = $completions->isEmpty() ? null : round(100 * $completions->filter(fn ($c) => $c->completed_on->lte($c->due_on))->count() / $completions->count(), 1);

        $findings = AuditFinding::whereHas('audit')->limit(self::ROW_CAP)->get(['id', 'severity_id', 'status_id', 'due_on']);
        $map = $this->lookupMap($findings->pluck('severity_id')->merge($findings->pluck('status_id')));
        $open = $findings->filter(fn ($f) => !in_array($map[$f->status_id]['code'] ?? '', ['resolved', 'accepted_risk'], true));
        $overdueFindings = $open->filter(fn ($f) => $f->due_on && $f->due_on->toDateString() < $today)->count();
        $bySeverity = $open->groupBy('severity_id')->map(fn ($g, $id) => ['label' => $map[$id]['label'] ?? '—', 'sort' => $map[$id]['sort'] ?? 0, 'value' => $g->count()])
            ->sortBy('sort')->values()->map(fn ($r) => Arr::except($r, 'sort'))->all();
        $byStatus = $findings->groupBy('status_id')->map(fn ($g, $id) => ['name' => $map[$id]['label'] ?? '—', 'value' => $g->count(), 'color' => $this->color($map[$id]['color'] ?? null)])->values()->all();

        $auditsDone = ComplianceAudit::whereBetween('completed_on', [$p['from']->toDateString(), $p['to']->toDateString()])->count();

        $verifs = BackupVerification::whereBetween('backup_date', [$p['from']->toDateString(), $p['to']->toDateString()])->limit(self::ROW_CAP)->get(['backup_date', 'result_id']);
        $rmap = $this->lookupMap($verifs->pluck('result_id'));
        $okCount = $verifs->filter(fn ($v) => ($rmap[$v->result_id]['code'] ?? '') === 'verified_ok')->count();
        $okRate = $verifs->isEmpty() ? null : round(100 * $okCount / $verifs->count(), 1);

        $b = $this->buckets($p);
        $stack = [];
        foreach ($b['keys'] as $k) $stack[$k] = ['label' => $b['labels'][$k], 'ok' => 0, 'issues' => 0, 'failed' => 0];
        foreach ($verifs as $v) {
            $k = $this->bucketKey(CarbonImmutable::parse($v->backup_date->toDateString(), $p['tz']), $b['granularity']);
            if (!isset($stack[$k])) continue;
            $c = $rmap[$v->result_id]['code'] ?? '';
            $stack[$k][$c === 'verified_ok' ? 'ok' : ($c === 'failed' ? 'failed' : 'issues')]++;
        }

        $lastOk = $this->daysSinceLastOkBackup($p);
        $soon = $p['today']->addDays(14)->toDateString();
        $rows = $obligations->map(fn ($o) => [
            'duty' => $o->title, 'frequency' => $o->frequency?->label($this->langId()) ?? '—', 'next_due_on' => $o->next_due_on->toDateString(),
            'last_completed_on' => $o->last_completed_on?->toDateString() ?? '—',
            'status' => $o->next_due_on->toDateString() < $today ? $this->t('status.overdue') : ($o->next_due_on->toDateString() <= $soon ? $this->t('status.due_soon') : $this->t('status.on_track')),
        ])->all();

        return [
            'kpis' => [
                $this->kpi('obligations_overdue', $overdue, null, $overdue ? 'bad' : 'good'),
                $this->kpi('on_time_rate', $onTime, 'percent', $onTime === null ? null : ($onTime >= 90 ? 'good' : ($onTime >= 70 ? 'warn' : 'bad'))),
                $this->kpi('open_findings', $open->count(), null, $open->count() ? 'warn' : 'good'),
                $this->kpi('findings_overdue', $overdueFindings, null, $overdueFindings ? 'bad' : 'good'),
                $this->kpi('audits_completed', $auditsDone),
                $this->kpi('backup_ok_rate', $okRate, 'percent', $okRate === null ? null : ($okRate >= 95 ? 'good' : ($okRate >= 80 ? 'warn' : 'bad'))),
                $this->kpi('days_since_backup', $lastOk, 'days', $lastOk === null ? 'bad' : ($lastOk > 14 ? 'bad' : ($lastOk > 7 ? 'warn' : 'good'))),
            ],
            'charts' => [
                ['key' => 'findings_severity', 'type' => 'bar', 'title' => $this->t('chart.findings_by_severity'), 'x' => 'label', 'series' => [['name' => 'value', 'label' => $this->t('col.count'), 'color' => 'red.6']], 'data' => $bySeverity],
                ['key' => 'findings_status', 'type' => 'donut', 'title' => $this->t('chart.findings_by_status'), 'data' => $byStatus],
                ['key' => 'backup_results', 'type' => 'stacked_bar', 'title' => $this->t('chart.backup_results'), 'x' => 'label',
                    'series' => [['name' => 'ok', 'label' => $this->t('col.ok'), 'color' => 'green.6'], ['name' => 'issues', 'label' => $this->t('col.issues'), 'color' => 'orange.6'], ['name' => 'failed', 'label' => $this->t('col.failed'), 'color' => 'red.6']],
                    'data' => array_values($stack)],
            ],
            'table' => $this->table(['duty', 'frequency', 'next_due_on', 'last_completed_on', 'status'], $rows),
        ];
    }

    /* ======================================================================== training ===== */

    private function training(array $p): array
    {
        $sessions = TrainingSession::with(['topic.translations', 'attendees'])->whereBetween('session_date', [$p['fromDb'], $p['toDb']])
            ->whereHas('status', fn ($q) => $q->where('code', 'held'))->orderBy('session_date')->limit(1000)->get();
        $mandatory = $sessions->where('is_mandatory', true);
        $invited = $sessions->sum(fn ($s) => $s->attendees->count());
        $attended = $sessions->sum(fn ($s) => $s->attendees->where('attended', true)->count());
        $rate = $invited ? round(100 * $attended / $invited, 1) : null;

        // Same definition as the quarterly training report: active members with no attendance at any mandatory session held in the period.
        $members = Membership::where('organization_id', $this->ctx->organizationId())->whereHas('status', fn ($s) => $s->where('code', 'active'))->pluck('user_id');
        $attendedIds = $mandatory->flatMap(fn ($s) => $s->attendees->where('attended', true)->pluck('user_id'))->unique();
        $without = $mandatory->isEmpty() ? null : $members->reject(fn ($id) => $attendedIds->contains($id))->count();

        $rows = $sessions->map(function ($s) use ($p) {
            $inv = $s->attendees->count();
            $att = $s->attendees->where('attended', true)->count();
            return [
                'date' => $s->session_date->setTimezone($p['tz'])->toDateString(), 'title' => $s->title, 'topic' => $s->topic?->label($this->langId()) ?? '—',
                'mandatory' => $this->t($s->is_mandatory ? 'yes' : 'no'), 'invited' => $inv, 'attended' => $att,
                'rate' => $inv ? round(100 * $att / $inv, 1) . ' %' : '—',
            ];
        })->values()->all();
        $chart = $sessions->map(fn ($s) => [
            'label' => $s->session_date->setTimezone($p['tz'])->format('d/m') . ' ' . Str::limit($s->title, 18),
            'invited' => $s->attendees->count(), 'attended' => $s->attendees->where('attended', true)->count(),
        ])->values()->all();

        return [
            'kpis' => [
                $this->kpi('sessions_held', $sessions->count()),
                $this->kpi('mandatory_held', $mandatory->count()),
                $this->kpi('invited', $invited),
                $this->kpi('attended', $attended),
                $this->kpi('attendance_rate', $rate, 'percent', $rate === null ? null : ($rate >= 85 ? 'good' : ($rate >= 60 ? 'warn' : 'bad'))),
                $this->kpi('people_without_attendance', $without, null, $without === null ? null : ($without ? 'warn' : 'good')),
            ],
            'charts' => [
                ['key' => 'attendance', 'type' => 'bar', 'title' => $this->t('chart.attendance_by_session'), 'x' => 'label',
                    'series' => [['name' => 'invited', 'label' => $this->t('col.invited'), 'color' => 'gray.5'], ['name' => 'attended', 'label' => $this->t('col.attended'), 'color' => 'secondary.6']], 'data' => $chart],
            ],
            'table' => $this->table(['date', 'title', 'topic', 'mandatory', 'invited', 'attended', 'rate'], $rows),
        ];
    }

    /* ======================================================================== delivery ===== */

    private function delivery(array $p): array
    {
        $rows = NotificationDelivery::whereBetween('created_at', [$p['fromDb'], $p['toDb']])->limit(self::ROW_CAP)->get(['channel_id', 'status_id', 'created_at', 'attempts', 'error']);
        $map = $this->lookupMap($rows->pluck('channel_id')->merge($rows->pluck('status_id')));
        $st = fn ($r) => $map[$r->status_id]['code'] ?? '';
        $sent = $rows->filter(fn ($r) => $st($r) === 'sent')->count();
        $failed = $rows->filter(fn ($r) => $st($r) === 'failed')->count();
        $pending = $rows->filter(fn ($r) => $st($r) === 'pending')->count();
        $final = $sent + $failed;
        $failRate = $final ? round(100 * $failed / $final, 1) : null;
        $avgAttempts = $rows->isEmpty() ? null : round((float) $rows->avg('attempts'), 2);

        $b = $this->buckets($p);
        $sentB = $this->countByBucket($rows->filter(fn ($r) => $st($r) === 'sent')->pluck('created_at'), $b, $p);
        $failB = $this->countByBucket($rows->filter(fn ($r) => $st($r) === 'failed')->pluck('created_at'), $b, $p);
        $trend = array_map(fn ($k) => ['label' => $b['labels'][$k], 'sent' => $sentB[$k], 'failed' => $failB[$k]], $b['keys']);

        $byChannel = $rows->groupBy('channel_id')->map(fn ($g, $id) => [
            'label' => $map[$id]['label'] ?? '—', 'sent' => $g->filter(fn ($r) => $st($r) === 'sent')->count(),
            'failed' => $g->filter(fn ($r) => $st($r) === 'failed')->count(), 'pending' => $g->filter(fn ($r) => $st($r) === 'pending')->count(),
        ])->values()->all();

        // Error text is grouped and scrubbed: e-mail addresses and long digit runs (phone numbers) never leave the server.
        $errors = $rows->filter(fn ($r) => $st($r) === 'failed' && $r->error)->groupBy(function ($r) use ($map) {
            $e = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', (string) $r->error);
            $e = preg_replace('/\+?\d[\d\s-]{6,}\d/', '[number]', (string) $e);
            return ($map[$r->channel_id]['label'] ?? '—') . '||' . mb_substr((string) $e, 0, 120);
        })->map(function ($g, $key) {
            [$channel, $error] = explode('||', $key, 2);
            return ['channel' => $channel, 'error' => $error, 'count' => $g->count()];
        })->sortByDesc('count')->take(50)->values()->all();

        return [
            'kpis' => [
                $this->kpi('deliveries', $rows->count()),
                $this->kpi('sent', $sent),
                $this->kpi('failed', $failed, null, $failed ? 'warn' : 'good'),
                $this->kpi('pending', $pending),
                $this->kpi('failure_rate', $failRate, 'percent', $failRate === null ? null : ($failRate <= 2 ? 'good' : ($failRate <= 10 ? 'warn' : 'bad'))),
                $this->kpi('avg_attempts', $avgAttempts),
            ],
            'charts' => [
                ['key' => 'delivery_trend', 'type' => 'line', 'title' => $this->t('chart.delivery_trend'), 'x' => 'label', 'series' => [['name' => 'sent', 'label' => $this->t('col.sent'), 'color' => 'green.6'], ['name' => 'failed', 'label' => $this->t('col.failed'), 'color' => 'red.6']], 'data' => $trend],
                ['key' => 'by_channel', 'type' => 'stacked_bar', 'title' => $this->t('chart.by_channel'), 'x' => 'label', 'series' => [['name' => 'sent', 'label' => $this->t('col.sent'), 'color' => 'green.6'], ['name' => 'failed', 'label' => $this->t('col.failed'), 'color' => 'red.6'], ['name' => 'pending', 'label' => $this->t('col.pending'), 'color' => 'yellow.6']], 'data' => $byChannel],
            ],
            'table' => $this->table(['channel', 'error', 'count'], $errors),
        ];
    }

    /* ========================================================================== helpers ===== */

    private function overdueEquipment(array $p): int
    {
        return EquipmentMovement::whereHas('status', fn ($q) => $q->where('code', 'checked_out'))->whereDate('expected_return_date', '<', $p['today']->toDateString())->count();
    }

    private function openFindings()
    {
        return AuditFinding::whereHas('audit')->whereHas('status', fn ($q) => $q->whereNotIn('code', ['resolved', 'accepted_risk']));
    }

    private function daysSinceLastOkBackup(array $p): ?int
    {
        $d = BackupVerification::whereHas('result', fn ($q) => $q->where('code', 'verified_ok'))->max('backup_date');
        return $d ? (int) round(CarbonImmutable::parse((string) $d, $p['tz'])->startOfDay()->diffInDays($p['today'])) : null;
    }

    private function median(array $v): ?float
    {
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
    }

    private function kpi(string $key, int|float|null $value, ?string $unit = null, ?string $tone = null): array
    {
        return ['key' => $key, 'label' => $this->t("kpi.$key"), 'value' => $value, 'unit' => $unit, 'tone' => $tone ?? 'neutral'];
    }

    /** 'days' | 'hours' | 'percent' are translated; anything else (a currency code) is shown as is. */
    private function unit(string $u): string
    {
        return in_array($u, ['days', 'hours', 'percent'], true) ? $this->t("unit.$u") : $u;
    }

    /** Table with localised column headings. */
    private function table(array $columns, array $rows): array
    {
        return ['columns' => array_map(fn ($c) => ['key' => $c, 'label' => $this->t("col.$c")], $columns), 'rows' => $rows];
    }

    /** Weekly buckets up to 120 days, monthly beyond. */
    private function buckets(array $p): array
    {
        $g = $p['from']->diffInDays($p['to']) <= 120 ? 'week' : 'month';
        $cur = $g === 'week' ? $p['from']->startOfWeek() : $p['from']->startOfMonth();
        $keys = $labels = [];
        while ($cur->lte($p['to'])) {
            $k = $cur->toDateString();
            $keys[] = $k;
            $labels[$k] = $g === 'week' ? $cur->translatedFormat('d M') : $cur->translatedFormat('M Y');
            $cur = $g === 'week' ? $cur->addWeek() : $cur->addMonth();
        }
        return ['granularity' => $g, 'keys' => $keys, 'labels' => $labels];
    }

    private function bucketKey(CarbonImmutable $d, string $granularity): string
    {
        return ($granularity === 'week' ? $d->startOfWeek() : $d->startOfMonth())->toDateString();
    }

    /** @return array<string,int> */
    private function countByBucket(Collection $timestamps, array $b, array $p): array
    {
        $out = array_fill_keys($b['keys'], 0);
        foreach ($timestamps as $ts) {
            if (!$ts) continue;
            $k = $this->bucketKey(CarbonImmutable::instance($ts)->setTimezone($p['tz']), $b['granularity']);
            if (isset($out[$k])) $out[$k]++;
        }
        return $out;
    }

    /** id => [code, label, color, sort] for the lookup values used by a result set. */
    private function lookupMap(Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();
        if ($ids->isEmpty()) return [];
        $lang = $this->langId();
        return LookupValue::with('translations')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($v) => [$v->id => ['code' => $v->code, 'label' => $v->label($lang), 'color' => $v->color, 'sort' => $v->sort_order]])->all();
    }

    private function color(?string $c): string { return ($c ?: 'gray') . '.6'; }

    private function langId(): ?string { return request()->user()?->language_id; }

    private function timezone(): string
    {
        return $this->ctx->organization()?->timezone?->code ?: config('app.timezone');
    }

    private function t(string $key): string { return __("reports.$key"); }
}
