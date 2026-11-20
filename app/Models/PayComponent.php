<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayComponent extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_components';
    protected $guarded = [];
    protected $casts = ['is_taxable' => 'boolean', 'is_pensionable' => 'boolean', 'is_active' => 'boolean'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function source() { return $this->belongsTo(LookupValue::class, 'source_id'); }
}
