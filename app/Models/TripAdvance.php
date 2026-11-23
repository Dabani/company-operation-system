<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripAdvance extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_advances';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'last_reminded_on' => 'date:Y-m-d', 'retired_at' => \App\Casts\UtcDateTime::class];

    public function trip() { return $this->belongsTo(Trip::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
