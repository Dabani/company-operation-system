<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function invoice() { return $this->belongsTo(SupplierInvoice::class, 'invoice_id'); }
    public function orderLine() { return $this->belongsTo(PurchaseOrderLine::class, 'order_line_id'); }
}
