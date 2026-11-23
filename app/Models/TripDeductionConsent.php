<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripDeductionConsent extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_deduction_consents';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['amount' => 'decimal:2', 'consented_on' => 'date:Y-m-d'];
}
