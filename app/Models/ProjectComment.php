<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProjectComment extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_client_visible' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function clientIdentity() { return $this->belongsTo(ClientIdentity::class); }
}
