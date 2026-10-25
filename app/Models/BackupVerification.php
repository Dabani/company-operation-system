<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BackupVerification extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'backup_verifications';
    protected $guarded = [];
    protected $casts = ['backup_date' => 'date', 'verified_at' => 'datetime'];

    public function location() { return $this->belongsTo(LookupValue::class, 'location_id'); }
    public function result() { return $this->belongsTo(LookupValue::class, 'result_id'); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
