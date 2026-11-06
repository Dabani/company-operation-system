<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function lines() { return $this->hasMany(RateCardLine::class)->orderByDesc('effective_from'); }
}
