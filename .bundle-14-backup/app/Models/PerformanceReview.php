<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PerformanceReview extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_performance_reviews';
    protected $guarded = [];
    protected $casts = ['submitted_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function cycle() { return $this->belongsTo(ReviewCycle::class, 'cycle_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
    public function reviewer() { return $this->belongsTo(Employee::class, 'reviewer_employee_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function rating() { return $this->belongsTo(LookupValue::class, 'rating_id'); }
    public function recommendation() { return $this->belongsTo(LookupValue::class, 'recommendation_id'); }
}
