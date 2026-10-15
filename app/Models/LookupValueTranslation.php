<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LookupValueTranslation extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    public function language() { return $this->belongsTo(Language::class); }
}
