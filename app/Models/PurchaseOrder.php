<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'purchase_order';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['needed_by' => 'date', 'total_amount' => 'decimal:2', 'issued_at' => UtcDateTime::class, 'closed_at' => UtcDateTime::class];

    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'requisition_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function lines() { return $this->hasMany(PurchaseOrderLine::class, 'order_id')->orderBy('line_no'); }
    public function receipts() { return $this->hasMany(GoodsReceipt::class, 'order_id')->orderBy('received_on')->orderBy('created_at'); }
    public function history() { return $this->hasMany(PurchaseOrderHistory::class, 'order_id')->orderBy('created_at'); }
}
