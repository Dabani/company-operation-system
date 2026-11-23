<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TripExpense extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_trip_expenses';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'expense_date' => 'date:Y-m-d', 'is_generated' => 'boolean', 'reviewed_at' => \App\Casts\UtcDateTime::class];

    public function trip() { return $this->belongsTo(Trip::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
