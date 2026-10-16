<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'notification_deliveries';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['sent_at' => 'datetime', 'created_at' => 'datetime'];

    public function channel() { return $this->belongsTo(LookupValue::class, 'channel_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
}
