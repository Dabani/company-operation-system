<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'supplier_invoice';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date', 'paid_on' => 'date', 'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
        'exception_approved' => 'boolean', 'approved_at' => UtcDateTime::class];

    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
    public function lines() { return $this->hasMany(SupplierInvoiceLine::class, 'invoice_id'); }
    public function issues() { return $this->hasMany(SupplierInvoiceIssue::class, 'invoice_id'); }
    public function history() { return $this->hasMany(SupplierInvoiceHistory::class, 'invoice_id')->orderBy('created_at'); }
}
