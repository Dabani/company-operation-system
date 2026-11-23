<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'trip';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trips';
    protected $guarded = [];
    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'budget_estimate' => 'decimal:2', 'authorized_at' => \App\Casts\UtcDateTime::class,
        'report_submitted_at' => \App\Casts\UtcDateTime::class, 'cancelled_at' => \App\Casts\UtcDateTime::class];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function zone() { return $this->belongsTo(LookupValue::class, 'zone_id'); }
    public function currency() { return $this->belongsTo(LookupValue::class, 'currency_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function project() { return $this->belongsTo(Project::class); }
    public function travelers() { return $this->hasMany(TripTraveler::class); }
    public function bookings() { return $this->hasMany(TripBooking::class); }
    public function advances() { return $this->hasMany(TripAdvance::class); }
    public function expenses() { return $this->hasMany(TripExpense::class); }
    public function settlements() { return $this->hasMany(TripSettlement::class); }
}
