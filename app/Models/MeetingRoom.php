<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingRoom extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function location() { return $this->belongsTo(Location::class); }
    public function bookings() { return $this->hasMany(MeetingRoomBooking::class, 'room_id'); }
}
