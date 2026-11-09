<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Timesheet extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['week_start' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'total_seconds' => 'integer'];

    public function user() { return $this->belongsTo(User::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function entries() { return $this->hasMany(TimeEntry::class); }
}
