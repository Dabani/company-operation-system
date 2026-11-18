<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplierBankAccount extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['account_number'];
    protected $casts = ['account_number' => 'encrypted', 'verified_at' => UtcDateTime::class];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function proposer() { return $this->belongsTo(User::class, 'proposed_by'); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
