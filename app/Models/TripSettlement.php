<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripSettlement extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_settlements';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['accepted_total' => 'decimal:2', 'advance_total' => 'decimal:2', 'balance' => 'decimal:2', 'settled_at' => \App\Casts\UtcDateTime::class];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function resolution() { return $this->belongsTo(LookupValue::class, 'resolution_id'); }
    public function consent() { return $this->hasOne(TripDeductionConsent::class, 'settlement_id'); }
}
