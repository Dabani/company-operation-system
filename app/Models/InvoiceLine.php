<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoiceLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:4', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'rounded_minutes' => 'decimal:2', 'raw_seconds' => 'integer', 'line_no' => 'integer'];

    protected static function booted(): void
    {
        $guard = function (self $l) {
            if (Invoice::whereKey($l->invoice_id)->where('status_id', '!=', Lookup::id('invoice_status', 'draft'))->exists()) {
                throw new LogicException(__('billing.invoice.locked'));
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function sources() { return $this->hasMany(InvoiceLineSource::class); }
}
