<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['purchase_date' => 'date', 'warranty_expires_on' => 'date', 'purchase_cost' => 'decimal:2'];

    public function type() { return $this->belongsTo(LookupValue::class, 'type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function condition() { return $this->belongsTo(LookupValue::class, 'condition_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function department() { return $this->belongsTo(Department::class)->withoutGlobalScopes(); }
    public function location() { return $this->belongsTo(Location::class)->withoutGlobalScopes(); }
    public function events() { return $this->hasMany(AssetEvent::class); }
}
