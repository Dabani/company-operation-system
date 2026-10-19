<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FileDeletionRequest extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['decided_at' => 'datetime', 'created_at' => 'datetime'];

    public function file() { return $this->belongsTo(FileAsset::class, 'file_id')->withTrashed(); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function comment() { return $this->belongsTo(FileComment::class, 'comment_id'); }
}
