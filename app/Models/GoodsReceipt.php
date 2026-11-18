<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'goods_receipt';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['received_on' => 'date'];

    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function lines() { return $this->hasMany(GoodsReceiptLine::class, 'receipt_id'); }
}
