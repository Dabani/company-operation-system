<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RuleActivation extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
