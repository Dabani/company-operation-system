<?php

namespace App\Services\Procurement;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierStatusHistory;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier master (Guide 7.6): onboarding is reviewed through the approval engine, suspension and blocking need a reason, and a change of bank
 * details is proposed by one person and confirmed by another. Every change is audited; the status history cannot be altered.
 */
class SupplierService
{
    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("procurement.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('supplier_status', $code); }

    private function history(Supplier $s, ?string $from, string $to, ?User $by, ?string $note): void
    {
        SupplierStatusHistory::create(['organization_id' => $s->organization_id, 'supplier_id' => $s->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    public function create(array $d, User $by): Supplier
    {
        $s = Supplier::create($d + ['status_id' => $this->st('draft'), 'created_by' => $by->id]);
        $this->history($s, null, $s->status_id, $by, null);
        AuditLogger::log('supplier.created', $s, [], ['name' => $s->name]);
        return $s;
    }

    public function update(Supplier $s, array $d, User $by): Supplier
    {
        $s->loadMissing('status');
        if (!ProcurementRules::supplierEditable($s->status->code)) {
            $this->fail('status', 'supplier_not_editable');
        }
        $keys = array_keys($d);
        $old = array_map(fn ($v) => (string) $v, $s->only($keys));
        $s->update($d);
        AuditLogger::log('supplier.updated', $s, $old, array_map(fn ($v) => (string) $v, $s->only($keys)));
        return $s->refresh();
    }

    /** draft or blocked -> under_review, sent to the approval flow. Needs the facts a reviewer must check. */
    public function submit(Supplier $s, User $by): Supplier
    {
        return DB::transaction(function () use ($s, $by) {
            $s = Supplier::lockForUpdate()->with('status')->findOrFail($s->id);
            if (!in_array($s->status->code, ['draft', 'blocked'], true)) {
                $this->fail('status', 'supplier_bad_move');
            }
            foreach (['legal_name', 'tax_number', 'country_code'] as $k) {
                if (blank($s->{$k})) {
                    $this->fail($k, 'supplier_incomplete');
                }
            }
            if (blank($s->email) && blank($s->phone)) {
                $this->fail('email', 'supplier_incomplete');
            }
            $req = $this->approvals->submit('supplier', $s->id, $by, __('procurement.supplier_approval_title', ['name' => $s->name, 'code' => $s->code]));
            $from = $s->status_id;
            $s->update(['status_id' => $this->st('under_review'), 'approval_request_id' => $req->id, 'status_reason' => null]);
            $this->history($s, $from, $s->status_id, $by, null);
            AuditLogger::log('supplier.submitted', $s, [], ['name' => $s->name]);
            return $s->refresh();
        });
    }

    /** Called by the approval handler. Approved: usable for purchases. Anything else returns it to draft with the reviewer's note. */
    public function outcome(string $supplierId, string $outcome, ApprovalRequest $req): void
    {
        $s = Supplier::with('status')->find($supplierId);
        if (!$s || $s->status->code !== 'under_review') {
            return;
        }
        $last = ApprovalAction::where('request_id', $req->id)->orderByDesc('acted_at')->first();
        $note = $last?->comment ? mb_substr($last->comment, 0, 1000) : null;
        $to = $outcome === 'approved' ? 'approved' : 'draft';
        $from = $s->status_id;
        $s->update(['status_id' => $this->st($to), 'status_reason' => $outcome === 'approved' ? null : $note, 'approved_at' => $outcome === 'approved' ? now() : $s->approved_at]);
        $this->history($s, $from, $s->status_id, null, $note);
        AuditLogger::log('supplier.' . $outcome, $s, [], ['name' => $s->name, 'note' => $note]);
        if ($s->created_by) {
            Notifier::send(User::whereKey($s->created_by)->with('language')->get(), 'supplier_decided', ['name' => $s->name, 'outcome' => $outcome, 'note' => (string) $note],
                null, ['in_app'], '/suppliers/' . $s->id, $s->organization_id);
        }
    }

    /** Suspend, block, reinstate or archive by hand. */
    public function move(Supplier $s, string $to, ?string $note, User $by): Supplier
    {
        $s->loadMissing('status');
        $from = $s->status->code;
        if (!ProcurementRules::supplierManual($from, $to)) {
            $this->fail('status', 'supplier_bad_move');
        }
        $note = $note !== null ? trim($note) : null;
        if (ProcurementRules::supplierReasonRequired($to) && mb_strlen((string) $note) < 3) {
            $this->fail('note', 'supplier_reason_required');
        }
        $fromId = $s->status_id;
        $s->update(['status_id' => $this->st($to), 'status_reason' => $note ?: null]);
        $this->history($s, $fromId, $s->status_id, $by, $note ?: null);
        AuditLogger::log('supplier.' . $to, $s, ['status' => $from], ['status' => $to, 'note' => $note]);
        return $s->refresh();
    }

    // ---- bank details: proposed by one person, confirmed by another ------------------------------------------------------------
    private function bst(string $code): string { return Lookup::id('supplier_bank_status', $code); }

    public function proposeBank(Supplier $s, array $d, User $by): SupplierBankAccount
    {
        $s->loadMissing('status');
        if ($s->status->code === 'archived') {
            $this->fail('status', 'supplier_not_editable');
        }
        if (SupplierBankAccount::where('supplier_id', $s->id)->where('status_id', $this->bst('pending_verification'))->exists()) {
            $this->fail('account_number', 'bank_pending_exists');
        }
        $b = SupplierBankAccount::create($d + ['supplier_id' => $s->id, 'status_id' => $this->bst('pending_verification'), 'proposed_by' => $by->id]);
        AuditLogger::log('supplier.bank_proposed', $s, [], ['bank' => $b->bank_name, 'account' => ProcurementRules::maskAccount($b->account_number)]);
        return $b;
    }

    /** $accept true: this account becomes the verified one and the previous one is replaced. False: it is rejected. The verifier must not be the proposer. */
    public function verifyBank(SupplierBankAccount $b, bool $accept, ?string $note, User $by): SupplierBankAccount
    {
        return DB::transaction(function () use ($b, $accept, $note, $by) {
            $b = SupplierBankAccount::lockForUpdate()->findOrFail($b->id);
            if ($b->status_id !== $this->bst('pending_verification')) {
                $this->fail('status', 'bank_not_pending');
            }
            if (!ProcurementRules::canVerifyBank((string) $b->proposed_by, $by->id)) {
                $this->fail('status', 'bank_same_person');
            }
            if (!$accept && mb_strlen(trim((string) $note)) < 3) {
                $this->fail('note', 'supplier_reason_required');
            }
            if ($accept) {
                SupplierBankAccount::where('supplier_id', $b->supplier_id)->where('status_id', $this->bst('verified'))->update(['status_id' => $this->bst('replaced')]);
            }
            $b->update(['status_id' => $this->bst($accept ? 'verified' : 'rejected'), 'verified_by' => $by->id, 'verified_at' => now(), 'verification_note' => $note ? trim($note) : null]);
            AuditLogger::log($accept ? 'supplier.bank_verified' : 'supplier.bank_rejected', $b->supplier, [], ['account' => ProcurementRules::maskAccount($b->account_number), 'proposed_by' => $b->proposed_by]);
            return $b->refresh();
        });
    }
}
