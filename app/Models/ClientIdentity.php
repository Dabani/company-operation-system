<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** External (portal) login. Separate table, separate guard ("client"); it has no roles and never touches the permission matrix. */
class ClientIdentity extends Authenticatable
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];
    protected $casts = ['is_active' => 'boolean', 'last_login_at' => 'datetime', 'password' => 'hashed', 'two_factor_secret' => 'encrypted',
        'two_factor_confirmed_at' => 'datetime', 'invited_at' => 'datetime'];

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function client() { return $this->belongsTo(Client::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function contact() { return $this->belongsTo(ClientContact::class, 'client_contact_id'); }
    public function hasMfa(): bool { return $this->two_factor_confirmed_at !== null; }
}
