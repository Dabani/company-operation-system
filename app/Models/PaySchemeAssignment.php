<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PaySchemeAssignment extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_scheme_assignments';
    protected $guarded = [];
    protected $casts = ['applies' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'verified_at' => \App\Casts\UtcDateTime::class];

    public function scheme() { return $this->belongsTo(PayScheme::class, 'scheme_id'); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
    public function preparer() { return $this->belongsTo(User::class, 'prepared_by'); }
}
