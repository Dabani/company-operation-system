<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['rate_percent' => 'decimal:4', 'valid_from' => 'date', 'valid_to' => 'date', 'is_default' => 'boolean', 'verified_at' => 'datetime'];

    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }

    public function validOn(string $date): bool
    {
        return $this->valid_from->toDateString() <= $date && ($this->valid_to === null || $this->valid_to->toDateString() >= $date);
    }
}
