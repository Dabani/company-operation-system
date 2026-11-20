<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayScheme extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_schemes';
    protected $guarded = [];
    protected $casts = ['reduces_taxable_base' => 'boolean', 'round_each_band' => 'boolean', 'is_active' => 'boolean', 'calc_order' => 'integer', 'decimals' => 'integer'];

    public function statutoryType() { return $this->belongsTo(LookupValue::class, 'statutory_type_id'); }
    public function mode() { return $this->belongsTo(LookupValue::class, 'mode_id'); }
    public function baseKind() { return $this->belongsTo(LookupValue::class, 'base_kind_id'); }
    public function rounding() { return $this->belongsTo(LookupValue::class, 'rounding_id'); }
    public function versions() { return $this->hasMany(PaySchemeVersion::class, 'scheme_id'); }
}
