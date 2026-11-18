<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\Event;
use App\Models\EventExpense;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Services\AuditLogger;
use App\Services\Events\EventAccess;
use App\Services\Events\ExpenseRules;
use App\Services\Events\ExpenseService;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/** Event expenses (Appendix A.4): only the people who run the event see and change them; the approval itself goes through the approvals inbox. */
class EventExpenseController extends Controller
{
    public function __construct(private ExpenseService $svc, private EventAccess $access, private OrganizationContext $ctx) {}

    private function event(Request $r, string $id): Event
    {
        $e = $this->access->find($r->user(), $id, ['status']);
        $this->access->requireManage($r->user(), $e);
        return $e;
    }

    /** @return array{0:EventExpense,1:Event} */
    private function expense(Request $r, string $id): array
    {
        $x = EventExpense::with('status')->findOrFail($id);
        return [$x, $this->event($r, $x->event_id)];
    }

    private function row(EventExpense $x): array
    {
        $x->loadMissing(['status', 'category', 'creator:id,name']);
        $s = $x->status->code;
        return ['id' => $x->id, 'category' => $x->category->code, 'category_id' => $x->category_id, 'description' => $x->description, 'vendor' => $x->vendor, 'expense_date' => $x->expense_date->toDateString(),
            'amount' => $x->amount, 'status' => $s, 'over_budget' => $x->over_budget, 'decision_note' => $x->decision_note, 'receipts' => $this->svc->receipts($x), 'approval_request_id' => $x->approval_request_id,
            'created_by' => $x->creator?->name, 'me' => ['can_edit' => ExpenseRules::editable($s), 'can_submit' => $s === 'draft', 'can_attach' => in_array($s, ['draft', 'pending_approval'], true)]];
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'category_id' => [$req, Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'event_expense_category')->pluck('id')))],
            'description' => [$req, 'string', 'max:500'], 'vendor' => 'nullable|string|max:190', 'expense_date' => [$req, 'date_format:Y-m-d'], 'amount' => [$req, 'numeric', 'gt:0', 'max:999999999999'],
        ];
    }

    public function index(Request $request, string $id)
    {
        $e = $this->event($request, $id);
        $rows = EventExpense::where('event_id', $e->id)->with(['status', 'category', 'creator:id,name'])->orderBy('expense_date')->orderBy('created_at')->get();
        return ['data' => $rows->map(fn ($x) => $this->row($x)), 'meta' => ['summary' => $this->svc->summary($e), 'currency' => $e->currency_code]];
    }

    public function store(Request $request, string $id)
    {
        $e = $this->event($request, $id);
        $x = $this->svc->create($e, $request->validate($this->rules(true)), $request->user());
        return response(['data' => $this->row($x), 'meta' => ['summary' => $this->svc->summary($e)]], 201);
    }

    public function update(Request $request, string $id)
    {
        [$x, $e] = $this->expense($request, $id);
        $x = $this->svc->update($x, $request->validate($this->rules(false)), $request->user());
        return ['data' => $this->row($x), 'meta' => ['summary' => $this->svc->summary($e)]];
    }

    public function destroy(Request $request, string $id)
    {
        [$x] = $this->expense($request, $id);
        $this->svc->delete($x);
        return response()->noContent();
    }

    public function submit(Request $request, string $id)
    {
        [$x, $e] = $this->expense($request, $id);
        $x = $this->svc->submit($x, $e, $request->user());
        return ['data' => $this->row($x), 'meta' => ['summary' => $this->svc->summary($e)]];
    }

    // ---- receipts (Controlled Files) --------------------------------------------------------------------------------------------
    public function files(Request $request, string $id)
    {
        [$x] = $this->expense($request, $id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'event_expense'))->where('link_id', $x->id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function storeFile(Request $request, string $id)
    {
        [$x] = $this->expense($request, $id);
        abort_unless(in_array($x->status->code, ['draft', 'pending_approval'], true), 403, 'forbidden');
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')))]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('meetings.file_not_restricted'));
        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => 'event_expense', 'id' => $x->id])->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'event_expense' => $x->id, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    public function export(Request $request, string $id)
    {
        abort_unless($this->ctx->can('data.export'), 403);
        $e = $this->event($request, $id);
        $format = $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $keys = ['expense_date', 'category', 'description', 'vendor', 'amount', 'status', 'over_budget'];
        $rows = EventExpense::where('event_id', $e->id)->with(['status', 'category'])->orderBy('expense_date')->get()
            ->map(fn ($x) => [$x->expense_date->toDateString(), $x->category->code, $x->description, (string) $x->vendor, (string) $x->amount, $x->status->code, $x->over_budget ? 'yes' : 'no'])->all();
        AuditLogger::log('event.expenses_exported', $e, [], ['format' => $format, 'rows' => count($rows)]);
        return Excel::download(new ListExport($rows, $keys), 'event-expenses-' . $e->code . '.' . $format);
    }
}
