<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InvoiceLineSource extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['hourly_rate' => 'decimal:2', 'seconds' => 'integer'];

    public function timeEntry() { return $this->belongsTo(TimeEntry::class); }
}
