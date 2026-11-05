<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['acted_at' => 'datetime', 'step_order' => 'integer'];

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function onBehalfOf() { return $this->belongsTo(User::class, 'on_behalf_of_id'); }
    public function decision() { return $this->belongsTo(LookupValue::class, 'decision_id'); }
}
