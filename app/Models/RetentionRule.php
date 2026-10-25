<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RetentionRule extends Model
{
    use HasUlids;

    protected $table = 'retention_rules';
    protected $guarded = [];
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function disposalAction() { return $this->belongsTo(LookupValue::class, 'disposal_action_id'); }
    public function legalReference() { return $this->belongsTo(LegalReference::class); }
}
