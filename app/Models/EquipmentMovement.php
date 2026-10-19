<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EquipmentMovement extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['departure_date' => 'date', 'expected_return_date' => 'date', 'decided_at' => 'datetime', 'checked_out_at' => 'datetime', 'returned_at' => 'datetime', 'overdue_alerted_at' => 'datetime'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'decided_by'); }
    public function assets()
    {
        return $this->belongsToMany(Asset::class, 'equipment_movement_items', 'movement_id', 'asset_id')
            ->withoutGlobalScopes()->withPivot(['status_before_id', 'condition_out_id', 'condition_in_id']);
    }
}
