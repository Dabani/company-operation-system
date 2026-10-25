<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ManagementReport extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'management_reports';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['submitted_at' => 'datetime'];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
}
