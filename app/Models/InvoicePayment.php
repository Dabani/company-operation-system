<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Payments are never edited or deleted: a mistake is corrected by a reversal row (negative amount). */
class InvoicePayment extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date', 'created_at' => 'datetime'];

    public function method() { return $this->belongsTo(LookupValue::class, 'method_id'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
