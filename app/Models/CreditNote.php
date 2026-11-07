<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'issued_at' => 'datetime'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
