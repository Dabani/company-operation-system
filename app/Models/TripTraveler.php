<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Link row (composite key trip_id + employee_id): no id column, no timestamps. */
class TripTraveler extends Model
{
    use BelongsToOrganization;

    protected $table = 'hr_trip_travelers';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    public function trip() { return $this->belongsTo(Trip::class, 'trip_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
