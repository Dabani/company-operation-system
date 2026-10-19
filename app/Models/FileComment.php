<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FileComment extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
}
