<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EventExpense extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2', 'over_budget' => 'boolean', 'submitted_at' => UtcDateTime::class, 'decided_at' => UtcDateTime::class];

    public function event() { return $this->belongsTo(Event::class); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
