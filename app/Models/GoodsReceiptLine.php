<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['quantity_received' => 'decimal:3', 'quantity_accepted' => 'decimal:3'];

    public function receipt() { return $this->belongsTo(GoodsReceipt::class, 'receipt_id'); }
    public function orderLine() { return $this->belongsTo(PurchaseOrderLine::class, 'order_line_id'); }
}
