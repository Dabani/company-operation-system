<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'event';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['public_token'];
    protected $casts = [
        'starts_at' => UtcDateTime::class, 'ends_at' => UtcDateTime::class, 'registration_opens_at' => UtcDateTime::class, 'registration_closes_at' => UtcDateTime::class,
        'capacity' => 'integer', 'waitlist_enabled' => 'boolean', 'allow_guests' => 'boolean', 'budget_amount' => 'decimal:2',
    ];

    public function type() { return $this->belongsTo(LookupValue::class, 'type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function organizer() { return $this->belongsTo(User::class, 'organizer_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function registrations() { return $this->hasMany(EventRegistration::class); }
    public function attendance() { return $this->hasMany(EventAttendance::class); }
    public function history() { return $this->hasMany(EventStatusHistory::class)->orderBy('created_at'); }
}
