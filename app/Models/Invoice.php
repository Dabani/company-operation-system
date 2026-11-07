<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Invoice extends Model
{
    use HasUlids, BelongsToOrganization, \App\Support\BelongsToClient;

    protected $guarded = [];
    protected $casts = ['issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'tax_exempt' => 'boolean', 'rounding_minutes' => 'integer',
        'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'paid_total' => 'decimal:2', 'credited_total' => 'decimal:2', 'balance' => 'decimal:2', 'tax_percent' => 'decimal:4'];

    /** Once issued an invoice is a legal document: only the money/status bookkeeping may move; never deleted. */
    protected static function booted(): void
    {
        static::updating(function (self $i) {
            $editable = [Lookup::id('invoice_status', 'draft'), Lookup::id('invoice_status', 'pending_approval')];
            if (!in_array($i->getOriginal('status_id'), $editable, true) && array_diff(array_keys($i->getDirty()), ['status_id', 'paid_total', 'credited_total', 'balance', 'updated_at'])) {
                throw new LogicException(__('billing.invoice.locked'));
            }
        });
        static::deleting(fn () => throw new LogicException(__('billing.invoice.never_deleted')));
    }

    public function project() { return $this->belongsTo(Project::class); }
    public function client() { return $this->belongsTo(Client::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function model() { return $this->belongsTo(LookupValue::class, 'billing_model_id'); }
    public function lines() { return $this->hasMany(InvoiceLine::class)->orderBy('line_no'); }
    public function payments() { return $this->hasMany(InvoicePayment::class)->orderBy('created_at'); }
    public function creditNotes() { return $this->hasMany(CreditNote::class)->orderBy('created_at'); }
    public function taxRate() { return $this->belongsTo(TaxRate::class); }

    public function isDraftLike(): bool { return in_array($this->status->code, ['draft', 'pending_approval'], true); }
}
