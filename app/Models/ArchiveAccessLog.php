<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ArchiveAccessLog extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'archive_access_logs';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['decided_at' => 'datetime', 'accessed_at' => 'datetime', 'returned_at' => 'datetime', 'due_back_on' => 'date', 'created_at' => 'datetime'];

    public function record() { return $this->belongsTo(ArchiveRecord::class, 'record_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function authorizer() { return $this->belongsTo(User::class, 'authorized_by'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
