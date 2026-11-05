<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LegalParameter extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['value_decimal' => 'decimal:4', 'valid_from' => 'date', 'valid_to' => 'date', 'verified_at' => 'datetime'];

    public function domain() { return $this->belongsTo(LookupValue::class, 'domain_id'); }
    public function boundType() { return $this->belongsTo(LookupValue::class, 'bound_type_id'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
}
