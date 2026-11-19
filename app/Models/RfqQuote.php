<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RfqQuote extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['valid_until' => 'date', 'total_amount' => 'decimal:2', 'declined' => 'boolean', 'submitted_at' => UtcDateTime::class];

    public function rfq() { return $this->belongsTo(Rfq::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function lines() { return $this->hasMany(RfqQuoteLine::class, 'quote_id'); }
}
