<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TaskStatusHistory extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'task_status_history';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function from() { return $this->belongsTo(LookupValue::class, 'from_status_id'); }
    public function to() { return $this->belongsTo(LookupValue::class, 'to_status_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
