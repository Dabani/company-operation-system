<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LeaveRequestDay extends Model
{
    use BelongsToOrganization;

    protected $table = 'hr_leave_request_days';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['leave_date' => 'date:Y-m-d', 'fraction' => 'decimal:2'];

    public function request() { return $this->belongsTo(LeaveRequest::class, 'request_id'); }
}
