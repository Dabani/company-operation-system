<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripBooking extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_bookings';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
}
