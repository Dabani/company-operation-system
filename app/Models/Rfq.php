<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Rfq extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'rfq';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['closing_date' => 'date', 'awarded_at' => UtcDateTime::class];

    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'requisition_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function quotes() { return $this->hasMany(RfqQuote::class)->orderBy('created_at'); }
    public function history() { return $this->hasMany(RfqHistory::class)->orderBy('created_at'); }
}
