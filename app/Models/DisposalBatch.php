<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DisposalBatch extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'disposal_batches';
    protected $guarded = [];
    protected $casts = ['decided_at' => 'datetime', 'executed_at' => 'datetime'];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function proposer() { return $this->belongsTo(User::class, 'proposed_by'); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
    public function executor() { return $this->belongsTo(User::class, 'executed_by'); }
    public function records() { return $this->belongsToMany(ArchiveRecord::class, 'disposal_batch_items', 'batch_id', 'record_id')->withoutGlobalScopes(); }
}
