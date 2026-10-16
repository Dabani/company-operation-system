<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CaseComment extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_internal' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function supportCase() { return $this->belongsTo(SupportCase::class, 'case_id'); }
}
