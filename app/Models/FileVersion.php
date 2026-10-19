<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FileVersion extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime', 'purged_at' => 'datetime', 'size_bytes' => 'integer'];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function file() { return $this->belongsTo(FileAsset::class, 'file_id')->withoutGlobalScopes()->withTrashed(); }
}
