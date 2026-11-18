<?php

namespace App\Services\Events;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Event;
use App\Models\EventExpense;
use App\Models\FileLink;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Event expenses (Appendix A.4): drafted by the organizer, approved through the approval engine, tracked against the event budget. */
class ExpenseService
{
    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("events.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('event_expense_status', $code); }

    private function requireCurrency(Event $e): void
    {
        if (!$e->currency_code) {
            $this->fail('amount', 'expense_currency_required');
        }
    }

    public function create(Event $e, array $d, User $by): EventExpense
    {
        $this->requireCurrency($e);
        $this->checkAmount($d['amount']);
        $x = EventExpense::create($d + ['event_id' => $e->id, 'status_id' => $this->st('draft'), 'created_by' => $by->id]);
        AuditLogger::log('event.expense_created', $x, [], ['event' => $e->code, 'amount' => (string) $x->amount]);
        return $x;
    }

    public function update(EventExpense $x, array $d, User $by): EventExpense
    {
        $x->loadMissing('status');
        if (!ExpenseRules::editable($x->status->code)) {
            $this->fail('status', 'expense_not_editable');
        }
        if (isset($d['amount'])) {
            $this->checkAmount($d['amount']);
        }
        $old = $x->only(['description', 'amount', 'expense_date']);
        $x->update($d);
        AuditLogger::log('event.expense_updated', $x, array_map(fn ($v) => (string) $v, $old), array_map(fn ($v) => (string) $v, $x->only(array_keys($old))));
        return $x->refresh();
    }

    private function checkAmount(string|int|float $amount): void
    {
        if (!ExpenseRules::amountValid(ExpenseRules::toCents($amount))) {
            $this->fail('amount', 'expense_bad_amount');
        }
    }

    public function delete(EventExpense $x): void
    {
        $x->loadMissing('status');
        if (!ExpenseRules::editable($x->status->code)) {
            $this->fail('status', 'expense_not_editable');
        }
        AuditLogger::log('event.expense_deleted', $x, ['amount' => (string) $x->amount], []);
        $x->delete();
    }

    public function receipts(EventExpense $x): int
    {
        return FileLink::where('link_type_id', Lookup::id('file_link_type', 'event_expense'))->where('link_id', $x->id)->count();
    }

    /** Committed money: approved plus pending, optionally leaving one expense out (the one being submitted). */
    public function committedCents(Event $e, ?string $exceptId = null): int
    {
        $ids = [$this->st('approved'), $this->st('pending_approval')];
        $sum = EventExpense::where('event_id', $e->id)->whereIn('status_id', $ids)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->sum('amount');
        return ExpenseRules::toCents($sum);
    }

    public function submit(EventExpense $x, Event $e, User $by): EventExpense
    {
        return DB::transaction(function () use ($x, $e, $by) {
            $x = EventExpense::lockForUpdate()->with('status')->findOrFail($x->id);
            if ($x->status->code !== 'draft') {
                $this->fail('status', 'expense_not_editable');
            }
            $cents = ExpenseRules::toCents($x->amount);
            $threshold = ExpenseRules::toCents((string) SettingsService::get($x->organization_id, 'event.expense_receipt_over'));
            if (ExpenseRules::receiptRequired($threshold, $cents) && $this->receipts($x) === 0) {
                $this->fail('receipt', 'expense_receipt_required');
            }
            $budget = $e->budget_amount !== null ? ExpenseRules::toCents($e->budget_amount) : null;
            $over = ExpenseRules::exceedsBudget($budget, $this->committedCents($e, $x->id), $cents);
            $title = __('events.expense_approval_title', ['event' => $e->title, 'what' => $x->description]) . ($over ? ' ' . __('events.expense_over_budget_tag') : '');
            $req = $this->approvals->submit('event_expense', $x->id, $by, $title, ['amount' => (string) $x->amount, 'currency' => $e->currency_code, 'department_id' => $e->department_id]);
            $x->update(['status_id' => $this->st('pending_approval'), 'over_budget' => $over, 'approval_request_id' => $req->id, 'submitted_at' => now(), 'decision_note' => null]);
            AuditLogger::log('event.expense_submitted', $x, [], ['event' => $e->code, 'amount' => (string) $x->amount, 'over_budget' => $over]);
            return $x->refresh();
        });
    }

    /** Called by the approval handler. Approved stands; rejected, returned or cancelled go back to the organizer (rejected can be corrected and resubmitted). */
    public function outcome(string $expenseId, string $outcome, ApprovalRequest $req): void
    {
        $x = EventExpense::with(['status', 'event'])->find($expenseId);
        if (!$x || $x->status->code !== 'pending_approval') {
            return;
        }
        $last = ApprovalAction::where('request_id', $req->id)->orderByDesc('acted_at')->first();
        $note = $last?->comment ? mb_substr($last->comment, 0, 2000) : null;
        $to = $outcome === 'approved' ? 'approved' : ($outcome === 'rejected' ? 'rejected' : 'draft');
        $x->update(['status_id' => $this->st($to), 'decided_at' => now(), 'decision_note' => $note]);
        AuditLogger::log('event.expense_' . $outcome, $x, [], ['amount' => (string) $x->amount, 'note' => $note]);
        if ($x->created_by && $outcome !== 'approved') {
            Notifier::send(User::whereKey($x->created_by)->with('language')->get(), 'event_expense_returned', ['title' => $x->event->title, 'what' => $x->description, 'note' => (string) $note, 'outcome' => $outcome],
                null, ['in_app', 'email'], '/events/' . $x->event_id, $x->organization_id);
        } elseif ($x->created_by) {
            Notifier::send(User::whereKey($x->created_by)->with('language')->get(), 'event_expense_approved', ['title' => $x->event->title, 'what' => $x->description],
                null, ['in_app'], '/events/' . $x->event_id, $x->organization_id);
        }
    }

    /** Budget picture of an event. All money values are strings with two decimals. */
    public function summary(Event $e): array
    {
        $rows = EventExpense::where('event_id', $e->id)->with(['status', 'category'])->get();
        $sum = fn ($status) => ExpenseRules::toCents($rows->filter(fn ($r) => $r->status->code === $status)->sum('amount'));
        $approved = $sum('approved');
        $pending = $sum('pending_approval');
        $budget = $e->budget_amount !== null ? ExpenseRules::toCents($e->budget_amount) : null;
        $remaining = ExpenseRules::remainingCents($budget, $approved + $pending);
        $fmt = fn (?int $c) => $c === null ? null : number_format($c / 100, 2, '.', '');
        $byCat = $rows->filter(fn ($r) => $r->status->code === 'approved')->groupBy(fn ($r) => $r->category->code)->map(fn ($g) => $fmt(ExpenseRules::toCents($g->sum('amount'))));
        return ['currency' => $e->currency_code, 'budget' => $fmt($budget), 'approved' => $fmt($approved), 'pending' => $fmt($pending), 'remaining' => $fmt($remaining),
            'over_budget' => $remaining !== null && $remaining < 0, 'over_budget_items' => $rows->where('over_budget', true)->count(), 'by_category' => $byCat];
    }
}
