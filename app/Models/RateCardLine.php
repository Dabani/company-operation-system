<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RateCardLine extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['hourly_rate' => 'decimal:2', 'effective_from' => 'date', 'effective_to' => 'date'];

    public function scope() { return $this->belongsTo(LookupValue::class, 'scope_id'); }
    public function projectRole() { return $this->belongsTo(LookupValue::class, 'project_role_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function task() { return $this->belongsTo(Task::class); }
}
