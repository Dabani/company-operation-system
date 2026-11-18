<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'accepted_quantity' => 'decimal:3', 'line_no' => 'integer'];

    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
}
