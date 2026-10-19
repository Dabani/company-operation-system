<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileLink extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    public function type() { return $this->belongsTo(LookupValue::class, 'link_type_id'); }
}
