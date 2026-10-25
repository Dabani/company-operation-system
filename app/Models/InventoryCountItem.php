<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCountItem extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['found' => 'boolean', 'checked_at' => 'datetime'];

    public function record() { return $this->belongsTo(ArchiveRecord::class, 'record_id')->withoutGlobalScopes(); }
    public function count() { return $this->belongsTo(InventoryCount::class, 'count_id'); }
}
