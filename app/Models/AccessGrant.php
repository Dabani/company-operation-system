<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AccessGrant extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['granted_at' => 'datetime', 'expires_on' => 'date', 'last_reviewed_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function resource() { return $this->belongsTo(LookupValue::class, 'resource_id'); }
    public function level() { return $this->belongsTo(LookupValue::class, 'access_level_id'); }
    public function grantedBy() { return $this->belongsTo(User::class, 'granted_by'); }
    public function revokedBy() { return $this->belongsTo(User::class, 'revoked_by'); }

    /** active | expired | revoked */
    public function state(): string
    {
        if ($this->revoked_at) return 'revoked';
        if ($this->expires_on && $this->expires_on->isPast() && !$this->expires_on->isToday()) return 'expired';
        return 'active';
    }
}
