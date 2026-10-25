<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryCount extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'inventory_counts';
    protected $guarded = [];
    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function starter() { return $this->belongsTo(User::class, 'started_by'); }
    public function location() { return $this->belongsTo(Location::class)->withoutGlobalScopes(); }
    public function items() { return $this->hasMany(InventoryCountItem::class, 'count_id'); }
}
