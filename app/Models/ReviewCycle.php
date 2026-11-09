<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ReviewCycle extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_review_cycles';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
