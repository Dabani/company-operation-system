<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function permission() { return $this->belongsTo(Permission::class); }
    public function children() { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order'); }
}
