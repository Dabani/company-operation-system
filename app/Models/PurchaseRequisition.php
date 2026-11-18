<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'purchase_requisition';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['needed_by' => 'date', 'total_amount' => 'decimal:2', 'over_budget' => 'boolean', 'split_suspected' => 'boolean', 'submitted_at' => UtcDateTime::class, 'decided_at' => UtcDateTime::class];

    public function type() { return $this->belongsTo(LookupValue::class, 'procurement_type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function preferredSupplier() { return $this->belongsTo(Supplier::class, 'preferred_supplier_id'); }
    public function lines() { return $this->hasMany(PurchaseRequisitionLine::class, 'requisition_id')->orderBy('line_no'); }
    public function history() { return $this->hasMany(PurchaseRequisitionHistory::class, 'requisition_id')->orderBy('created_at'); }
}
