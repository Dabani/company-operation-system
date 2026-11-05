<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['step_order' => 'integer', 'escalate_after_hours' => 'integer'];

    public function flow() { return $this->belongsTo(ApprovalFlow::class, 'flow_id'); }
    public function type() { return $this->belongsTo(LookupValue::class, 'step_type_id'); }
    public function role() { return $this->belongsTo(Role::class)->withoutGlobalScopes(); }
}
