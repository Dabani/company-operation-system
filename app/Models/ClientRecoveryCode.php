<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One-time two-factor recovery code of a client portal user. Only the hash is stored; the table has no organization or timestamp columns. */
class ClientRecoveryCode extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['used_at' => 'datetime'];

    public function identity() { return $this->belongsTo(ClientIdentity::class, 'client_identity_id'); }
}
