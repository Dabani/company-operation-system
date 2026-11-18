<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionHistory extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'purchase_requisition_history';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function from() { return $this->belongsTo(LookupValue::class, 'from_status_id'); }
    public function to() { return $this->belongsTo(LookupValue::class, 'to_status_id'); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
