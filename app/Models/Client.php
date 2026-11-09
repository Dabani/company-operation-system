<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'client';
    use HasUlids, SoftDeletes, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['portal_enabled' => 'boolean'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function contacts() { return $this->hasMany(ClientContact::class); }
}
