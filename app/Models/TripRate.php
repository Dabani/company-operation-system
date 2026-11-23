<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripRate extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_rates';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'is_active' => 'boolean'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function zone() { return $this->belongsTo(LookupValue::class, 'zone_id'); }
    public function currency() { return $this->belongsTo(LookupValue::class, 'currency_id'); }
}
