<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PaySchemeVersion extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_scheme_versions';
    protected $guarded = [];
    protected $casts = ['employee_rate' => 'decimal:6', 'employer_rate' => 'decimal:6', 'base_cap' => 'decimal:2', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'verified_at' => \App\Casts\UtcDateTime::class];

    public function scheme() { return $this->belongsTo(PayScheme::class, 'scheme_id'); }
    public function brackets() { return $this->hasMany(PayTaxBracket::class, 'version_id'); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
    public function preparer() { return $this->belongsTo(User::class, 'prepared_by'); }
}
