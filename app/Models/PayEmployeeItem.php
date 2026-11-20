<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayEmployeeItem extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_employee_items';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'verified_at' => \App\Casts\UtcDateTime::class];

    public function component() { return $this->belongsTo(PayComponent::class, 'component_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
    public function preparer() { return $this->belongsTo(User::class, 'prepared_by'); }
}
