<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RuleCondition extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['value_decimal' => 'decimal:2', 'value_decimal_to' => 'decimal:2'];

    public function attribute() { return $this->belongsTo(LookupValue::class, 'attribute_id'); }
    public function operator() { return $this->belongsTo(LookupValue::class, 'operator_id'); }
}
