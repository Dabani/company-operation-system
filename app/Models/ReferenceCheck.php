<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ReferenceCheck extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_reference_checks';
    protected $guarded = [];
    protected $casts = ['checked_on' => 'date:Y-m-d'];

    public function result() { return $this->belongsTo(LookupValue::class, 'result_id'); }
}
