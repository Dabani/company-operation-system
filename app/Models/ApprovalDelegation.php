<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApprovalDelegation extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date', 'is_active' => 'boolean'];

    public function delegator() { return $this->belongsTo(User::class, 'delegator_id'); }
    public function delegate() { return $this->belongsTo(User::class, 'delegate_id'); }
}
