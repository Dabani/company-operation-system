<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BackupResource;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\BackupVerification;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Notifier;
use App\Services\ObligationService;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Backup Verification Register. */
class BackupController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['location.translations', 'result.translations', 'verifier']; }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    public function index(Request $request)
    {
        return $this->respondList($request, BackupVerification::query()->with($this->with()), BackupResource::class, ['notes'], ['backup_date', 'verified_at'],
            ['location_id', 'result_id'], ['backup_date', 'location_label', 'result_label', 'verified_by_name', 'verified_at']);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'backup_date' => 'required|date|before_or_equal:today', 'location_id' => ['required', $this->lookupRule('backup_location')],
            'result_id' => ['required', $this->lookupRule('backup_result')], 'notes' => 'nullable|string|max:20000',
        ]);
        $v = BackupVerification::create($d + ['verified_by' => $request->user()->id, 'verified_at' => now()]);
        $v->load($this->with());
        AuditLogger::log('backup.verified', $v, [], ['date' => $v->backup_date->toDateString(), 'location' => $v->location->code, 'result' => $v->result->code]);

        ObligationService::autoComplete($this->ctx->organizationId(), 'backup_verification', $request->user(), 'Backup verification ' . $v->backup_date->toDateString());

        if ($v->result->code !== 'verified_ok') {          // a bad backup is an incident-grade event: tell the people who can act
            $to = Notifier::usersWithPermission($this->ctx->organizationId(), 'backup.manage')->merge(Notifier::usersWithPermission($this->ctx->organizationId(), 'compliance.manage'))->unique('id')->reject(fn ($u) => $u->id === $request->user()->id);
            Notifier::send($to, 'backup_issue', ['loc' => $v->location->label(), 'result' => $v->result->label(), 'date' => $v->backup_date->toDateString(), 'user' => $request->user()->name], null, ['in_app', 'email'], '/compliance/backups', $this->ctx->organizationId());
        }
        return (new BackupResource($v))->response()->setStatusCode(201);
    }

    /** Coverage: last verification per location and a week-by-week grid for the last 8 weeks. */
    public function summary(Request $request)
    {
        $lang = $request->user()->language_id;
        $locations = LookupValue::visible()->with('translations')->where('is_active', true)->whereHas('type', fn ($t) => $t->where('code', 'backup_location'))->orderBy('sort_order')->get();
        $rows = BackupVerification::with('result')->where('backup_date', '>=', today()->subWeeks(8)->startOfWeek())->get();
        $all = BackupVerification::with('result')->orderByDesc('backup_date')->get()->groupBy('location_id');

        $weeks = collect(range(7, 0))->map(function ($i) use ($rows, $locations) {
            $start = today()->subWeeks($i)->startOfWeek(); $end = $start->copy()->endOfWeek();
            return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'locations' => $locations->mapWithKeys(fn ($l) => [$l->id => $rows->where('location_id', $l->id)->filter(fn ($r) => $r->backup_date->between($start, $end))->isNotEmpty()])];
        });

        return response()->json(['data' => [
            'locations' => $locations->map(function ($l) use ($all, $lang) {
                $last = optional($all->get($l->id))->first();
                return ['id' => $l->id, 'label' => $l->label($lang), 'last_date' => $last?->backup_date->toDateString(), 'last_result' => $last ? ['label' => $last->result->label($lang), 'color' => $last->result->color, 'code' => $last->result->code] : null,
                    'days_since' => $last ? $last->backup_date->diffInDays(today()) : null];
            })->values(),
            'weeks' => $weeks,
        ]]);
    }

    public function attachments(string $id)
    {
        $v = BackupVerification::findOrFail($id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'backup_verification'))->where('link_id', $v->id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function attach(Request $request, string $id)
    {
        $v = BackupVerification::findOrFail($id);
        $request->validate(['file' => 'required|file']);
        $asset = FileService::store($request->file('file'), $request->user(), ['classification_id' => Lookup::id('file_classification', 'confidential')], ['type' => 'backup_verification', 'id' => $v->id]);
        $asset->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'backup' => $v->backup_date->toDateString(), 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }
}
