<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceLineSource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateCardLine;
use App\Models\TaxRate;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\MoneyService;
use App\Services\Rules\RuleResolver;
use App\Services\SettingsService;
use App\Services\Time\RateResolver;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private RateResolver $rates, private ApprovalEngine $approvals, private RuleResolver $rules, private DocumentNumber $numbers) {}

    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("billing.$key", $p)]]); }

    // ---- draft generation ----------------------------------------------------------------------------------------------
    public function generateDraft(Project $p, User $by): Invoice
    {
        $p->loadMissing('status');
        if (!$p->client_id) $this->fail('project', 'invoice.no_client');
        if (!$p->billing_model_id) $this->fail('project', 'invoice.no_billing_model');
        $model = \App\Models\LookupValue::findOrFail($p->billing_model_id)->code;
        $open = Invoice::where('project_id', $p->id)->whereHas('status', fn ($s) => $s->whereIn('code', ['draft', 'pending_approval']))->exists();
        if ($open) $this->fail('project', 'invoice.draft_exists');

        $org = $p->organization_id;
        $inc = max(1, (int) SettingsService::get($org, 'billing.rounding_minutes'));
        $mode = (string) SettingsService::get($org, 'billing.rounding_mode');

        [$lines, $currency] = match ($model) {
            'time_and_materials' => $this->timeLines($p, $inc, $mode),
            'fixed_price' => $this->fixedLines($p),
            'milestone' => $this->milestoneLines($p),
            default => $this->fail('project', 'invoice.no_billing_model'),
        };
        if (!$lines) $this->fail('project', 'invoice.nothing_to_invoice');

        return DB::transaction(function () use ($p, $by, $model, $lines, $currency, $inc, $mode, $org) {
            $tax = TaxRate::where('is_default', true)->whereDate('valid_from', '<=', now()->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString()))->first();
            $inv = Invoice::create([
                'project_id' => $p->id, 'client_id' => $p->client_id, 'billing_model_id' => $p->billing_model_id, 'status_id' => Lookup::id('invoice_status', 'draft'),
                'currency_code' => $currency, 'payment_terms_days' => (int) SettingsService::get($org, 'billing.payment_terms_days'),
                'rounding_minutes' => $inc, 'rounding_mode' => $mode, 'tax_rate_id' => $tax?->id, 'tax_name' => $tax?->name, 'tax_percent' => $tax?->rate_percent,
                'created_by' => $by->id,
            ]);
            foreach ($lines as $i => $l) {
                $sources = $l['sources'] ?? [];
                unset($l['sources']);
                $line = InvoiceLine::create($l + ['invoice_id' => $inv->id, 'line_no' => $i + 1]);
                foreach ($sources as $s) InvoiceLineSource::create($s + ['invoice_line_id' => $line->id]);
            }
            $this->recompute($inv);
            AuditLogger::log('invoice.draft_created', $inv, [], ['project' => $p->code, 'model' => $model, 'lines' => count($lines)]);
            return $inv->refresh();
        });
    }

    /** Time and materials: approved, billable, not-yet-invoiced entries priced from the project's rate card. Entries with no rate block the draft. */
    private function timeLines(Project $p, int $inc, string $mode): array
    {
        if (!$p->rate_card_id) $this->fail('project', 'invoice.no_rate_card');
        $card = RateCard::findOrFail($p->rate_card_id);
        $entries = TimeEntry::where('project_id', $p->id)->where('is_billable', true)->whereHas('status', fn ($s) => $s->where('code', 'approved'))
            ->whereNotIn('id', DB::table('invoice_line_sources')->select('time_entry_id'))->orderBy('work_date')->get();
        $groups = [];
        $unrated = 0;
        foreach ($entries as $e) {
            $r = $this->rates->rateFor($p, $e->user_id, $e->task_id, $e->work_date->toDateString());
            if (!$r) { $unrated++; continue; }
            $g = &$groups[$r['line_id']];
            $g['rate'] = $r['rate'];
            $g['seconds'] = ($g['seconds'] ?? 0) + $e->duration_seconds;
            $g['entries'][] = $e;
            unset($g);
        }
        if ($unrated > 0) $this->fail('project', 'invoice.unrated', ['count' => $unrated]);

        $lineModels = RateCardLine::with(['scope', 'projectRole', 'user:id,name', 'task:id,number,title'])->whereIn('id', array_keys($groups))->get()->keyBy('id');
        $out = [];
        foreach ($groups as $lineId => $g) {
            if ($g['seconds'] <= 0) $this->fail('project', 'invoice.negative_group');
            $m = InvoiceMath::timeLine($g['seconds'], $g['rate'], $inc, $mode);
            $rl = $lineModels[$lineId];
            $label = match ($rl->scope->code) {
                'employee' => $rl->user?->name, 'task' => $rl->task ? "#{$rl->task->number} {$rl->task->title}" : null,
                'role' => str_replace('_', ' ', (string) $rl->projectRole?->code), default => __('billing.pdf.standard_rate'),
            };
            $out[] = [
                'kind_id' => Lookup::id('invoice_line_kind', 'time'), 'description' => __('billing.pdf.professional_services') . ' - ' . $label,
                'quantity' => $m['quantity'], 'unit' => 'h', 'unit_price' => $g['rate'], 'amount' => $m['amount'], 'rate_card_line_id' => $lineId,
                'raw_seconds' => $m['raw_seconds'], 'rounded_minutes' => $m['rounded_minutes'],
                'sources' => collect($g['entries'])->map(fn ($e) => ['time_entry_id' => $e->id, 'seconds' => $e->duration_seconds, 'hourly_rate' => $g['rate']])->all(),
            ];
        }
        return [$out, $card->currency_code];
    }

    private function fixedLines(Project $p): array
    {
        if (!$p->currency_code) $this->fail('project', 'invoice.no_currency');
        if (!$p->budget_amount || MoneyService::compare((string) $p->budget_amount, '0') <= 0) $this->fail('project', 'invoice.no_fixed_amount');
        $already = InvoiceLine::whereHas('invoice', fn ($q) => $q->where('project_id', $p->id)->whereHas('status', fn ($s) => $s->where('code', '!=', 'cancelled')))
            ->where('kind_id', Lookup::id('invoice_line_kind', 'fixed'))->exists();
        if ($already) $this->fail('project', 'invoice.fixed_already');
        return [[[
            'kind_id' => Lookup::id('invoice_line_kind', 'fixed'), 'description' => __('billing.pdf.fixed_price') . ' - ' . $p->name,
            'quantity' => '1.0000', 'unit' => '', 'unit_price' => (string) $p->budget_amount, 'amount' => (string) $p->budget_amount,
        ]], strtoupper($p->currency_code)];
    }

    private function milestoneLines(Project $p): array
    {
        if (!$p->currency_code) $this->fail('project', 'invoice.no_currency');
        $ms = Milestone::where('project_id', $p->id)->whereNotNull('completed_at')->whereNotNull('billing_amount')->where('billing_amount', '>', 0)
            ->whereNotIn('id', InvoiceLine::whereNotNull('milestone_id')->select('milestone_id'))->orderBy('due_date')->get();
        return [$ms->map(fn ($m) => [
            'kind_id' => Lookup::id('invoice_line_kind', 'milestone'), 'description' => __('billing.pdf.milestone') . ' - ' . $m->name,
            'quantity' => '1.0000', 'unit' => '', 'unit_price' => (string) $m->billing_amount, 'amount' => (string) $m->billing_amount, 'milestone_id' => $m->id,
        ])->all(), strtoupper($p->currency_code)];
    }

    // ---- draft editing -------------------------------------------------------------------------------------------------------
    public function recompute(Invoice $inv): Invoice
    {
        $inv = Invoice::findOrFail($inv->id);
        $t = InvoiceMath::totals(InvoiceLine::where('invoice_id', $inv->id)->orderBy('line_no')->pluck('amount')->map(fn ($a) => (string) $a)->all(),
            ($inv->tax_exempt || $inv->tax_percent === null) ? null : (string) $inv->tax_percent);
        $inv->fill($t + ['balance' => InvoiceMath::balance($t['total'], (string) $inv->paid_total, (string) $inv->credited_total)])->save();
        return $inv;
    }

    public function updateDraft(Invoice $inv, array $d): Invoice
    {
        $this->assertDraft($inv);
        if (array_key_exists('tax_exempt', $d)) $inv->tax_exempt = (bool) $d['tax_exempt'];
        if (array_key_exists('tax_rate_id', $d)) {
            $rate = $d['tax_rate_id'] ? TaxRate::findOrFail($d['tax_rate_id']) : null;
            $inv->fill(['tax_rate_id' => $rate?->id, 'tax_name' => $rate?->name, 'tax_percent' => $rate?->rate_percent]);
        }
        foreach (['notes', 'payment_terms_days'] as $f) if (array_key_exists($f, $d)) $inv->{$f} = $d[$f] ?? ($f === 'payment_terms_days' ? $inv->payment_terms_days : null);
        $inv->save();
        AuditLogger::log('invoice.draft_updated', $inv, [], $d);
        return $this->recompute($inv);
    }

    public function addManualLine(Invoice $inv, string $description, string $quantity, string $unitPrice): InvoiceLine
    {
        $this->assertDraft($inv);
        $line = InvoiceLine::create(['invoice_id' => $inv->id, 'line_no' => (int) InvoiceLine::where('invoice_id', $inv->id)->max('line_no') + 1, 'kind_id' => Lookup::id('invoice_line_kind', 'manual'),
            'description' => $description, 'quantity' => $quantity, 'unit' => '', 'unit_price' => $unitPrice, 'amount' => InvoiceMath::lineAmount($quantity, $unitPrice)]);
        $this->recompute($inv);
        AuditLogger::log('invoice.line_added', $inv, [], ['description' => $description, 'amount' => (string) $line->amount]);
        return $line;
    }

    /** Only manual lines can be removed one by one; a generated line means cancelling the draft and generating again. */
    public function removeLine(Invoice $inv, string $lineId): void
    {
        $this->assertDraft($inv);
        $line = InvoiceLine::where('invoice_id', $inv->id)->findOrFail($lineId);
        if ($line->kind->code !== 'manual') $this->fail('line', 'invoice.line_generated');
        $line->delete();
        $this->recompute($inv);
        AuditLogger::log('invoice.line_removed', $inv);
    }

    public function cancelDraft(Invoice $inv): void
    {
        $this->assertDraft($inv);
        DB::transaction(function () use ($inv) {
            $ids = InvoiceLine::where('invoice_id', $inv->id)->pluck('id');
            InvoiceLineSource::whereIn('invoice_line_id', $ids)->delete();                    // release the time entries
            InvoiceLine::where('invoice_id', $inv->id)->delete();                              // release milestones
            $inv->update(['status_id' => Lookup::id('invoice_status', 'cancelled'), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0, 'balance' => 0]);
            AuditLogger::log('invoice.draft_cancelled', $inv);
        });
    }

    // ---- issue ---------------------------------------------------------------------------------------------------------------
    /** Validates, then either issues immediately (a rule says approval is not required) or raises the approval. Default = explicit approval. */
    public function requestIssue(Invoice $inv, User $by): Invoice
    {
        $inv->loadMissing('status');
        if ($inv->status->code !== 'draft') $this->fail('invoice', 'invoice.not_draft');
        $this->assertIssuable($inv);
        $r = $this->rules->resolve('billing', ['subject_type' => 'invoice', 'amount' => (string) $inv->total], 'invoice-approval', null, $inv->organization_id);
        if ($r && $r->get('approval_required') === false) {
            return $this->issue($inv, $by);
        }
        return DB::transaction(function () use ($inv, $by) {
            $req = $this->approvals->submit('invoice', $inv->id, $by, "Invoice {$inv->project->code} - {$inv->total} {$inv->currency_code}", ['amount' => (string) $inv->total, 'currency' => $inv->currency_code]);
            $inv->update(['status_id' => Lookup::id('invoice_status', 'pending_approval'), 'approval_request_id' => $req->id]);
            AuditLogger::log('invoice.submitted', $inv, [], ['total' => (string) $inv->total]);
            return $inv->refresh();
        });
    }

    public function issue(Invoice $inv, ?User $by): Invoice
    {
        return DB::transaction(function () use ($inv, $by) {
            $i = Invoice::lockForUpdate()->with(['status', 'client'])->findOrFail($inv->id);
            if (!$i->isDraftLike()) $this->fail('invoice', 'invoice.not_draft');
            $this->assertIssuable($i);
            $sources = InvoiceLineSource::whereIn('invoice_line_id', InvoiceLine::where('invoice_id', $i->id)->pluck('id'))->pluck('time_entry_id');
            $entries = TimeEntry::whereIn('id', $sources)->with('status')->get();
            if ($entries->contains(fn ($e) => $e->status->code !== 'approved')) $this->fail('invoice', 'invoice.entries_changed');

            $today = now()->toDateString();
            $i->update([
                'number' => $this->numbers->next($i->organization_id, 'INV'), 'status_id' => Lookup::id('invoice_status', 'issued'),
                'issue_date' => $today, 'due_date' => date('Y-m-d', strtotime("$today +{$i->payment_terms_days} days")),
                'client_name' => $i->client->name, 'client_address' => $i->client->address, 'client_tax_number' => $i->client->tax_number,
                'issued_by' => $by?->id, 'issued_at' => now(),
            ]);
            $invoiced = Lookup::id('time_entry_status', 'invoiced');
            foreach ($entries as $e) $e->update(['status_id' => $invoiced]);
            AuditLogger::log('invoice.issued', $i, [], ['number' => $i->number, 'total' => (string) $i->total, 'currency' => $i->currency_code]);
            return $i->refresh();
        });
    }

    public function revertToDraft(Invoice $inv, string $note): void
    {
        $i = Invoice::with('status')->findOrFail($inv->id);
        if ($i->status->code === 'pending_approval') {
            $i->update(['status_id' => Lookup::id('invoice_status', 'draft'), 'approval_request_id' => null]);
            AuditLogger::log('invoice.reverted', $i, [], ['note' => $note]);
        }
    }

    public function assertIssuable(Invoice $inv): void
    {
        $inv = Invoice::findOrFail($inv->id);
        if (InvoiceLine::where('invoice_id', $inv->id)->count() === 0) $this->fail('invoice', 'invoice.nothing_to_invoice');
        if (MoneyService::compare((string) $inv->total, '0') <= 0) $this->fail('invoice', 'invoice.zero_total');
        if (!$inv->tax_exempt) {
            $rate = $inv->tax_rate_id ? TaxRate::with('verification')->find($inv->tax_rate_id) : null;
            if (!$rate) $this->fail('invoice', 'invoice.tax_missing');
            if ($rate->verification->code !== 'verified') $this->fail('invoice', 'invoice.tax_unverified');
            if (!$rate->validOn(now()->toDateString())) $this->fail('invoice', 'invoice.tax_expired');
        }
    }

    private function assertDraft(Invoice $inv): void
    {
        $inv->loadMissing('status');
        if ($inv->status->code !== 'draft') $this->fail('invoice', 'invoice.not_draft');
    }
}
