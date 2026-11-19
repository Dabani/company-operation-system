<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RfqQuoteLine extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function quote() { return $this->belongsTo(RfqQuote::class, 'quote_id'); }
}
