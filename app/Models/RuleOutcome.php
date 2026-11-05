<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RuleOutcome extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['value_decimal' => 'decimal:2', 'value_integer' => 'integer', 'value_boolean' => 'boolean'];

    public function flow() { return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id'); }

    /** The single typed value this outcome carries. */
    public function value(): mixed
    {
        return $this->approval_flow_id ?? $this->value_text ?? $this->value_decimal ?? $this->value_integer ?? $this->value_boolean;
    }
}
