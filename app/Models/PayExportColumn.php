<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayExportColumn extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_export_columns';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['truncate' => 'boolean', 'decimals' => 'integer', 'width' => 'integer', 'position' => 'integer'];

    public function scheme() { return $this->belongsTo(PayScheme::class, 'scheme_id'); }
    public function component() { return $this->belongsTo(PayComponent::class, 'component_id'); }
}
