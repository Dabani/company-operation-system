<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileAsset extends Model
{
    use HasUlids, BelongsToOrganization, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['legal_hold' => 'boolean', 'archived_at' => 'datetime', 'size_bytes' => 'integer'];

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function classification() { return $this->belongsTo(LookupValue::class, 'classification_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function versions() { return $this->hasMany(FileVersion::class, 'file_id')->orderByDesc('version_number'); }
    public function currentVersion() { return $this->belongsTo(FileVersion::class, 'current_version_id'); }
    public function links() { return $this->hasMany(FileLink::class, 'file_id'); }
    public function comments() { return $this->hasMany(FileComment::class, 'file_id'); }
    public function deletionRequests() { return $this->hasMany(FileDeletionRequest::class, 'file_id'); }
}
