<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Requisition extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'requisition';
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_requisitions';
    protected $guarded = [];
    protected $casts = ['needed_by' => 'date:Y-m-d', 'headcount' => 'integer'];

    public function department() { return $this->belongsTo(Department::class); }
    public function position() { return $this->belongsTo(HrPosition::class, 'position_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function vacancy() { return $this->hasOne(Vacancy::class, 'requisition_id'); }
}
