<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ClientInvitation extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'last_sent_at' => 'datetime'];

    public function isExpired(): bool { return $this->expires_at->isPast(); }
}
