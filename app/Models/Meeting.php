<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Services\OrganizationContext;
use App\Support\BelongsToOrganization;
use App\Support\DepartmentScope;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'meeting';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['checkin_token'];
    protected $casts = [
        'starts_at' => UtcDateTime::class, 'ends_at' => UtcDateTime::class, 'rsvp_deadline' => UtcDateTime::class, 'agenda_approved_at' => UtcDateTime::class,
        'started_at' => UtcDateTime::class, 'ended_at' => UtcDateTime::class, 'closed_at' => UtcDateTime::class, 'cancelled_at' => UtcDateTime::class, 'ics_sequence' => 'integer',
    ];

    public function type() { return $this->belongsTo(LookupValue::class, 'type_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function confidentiality() { return $this->belongsTo(LookupValue::class, 'confidentiality_id'); }
    public function agendaStatus() { return $this->belongsTo(LookupValue::class, 'agenda_status_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function organizer() { return $this->belongsTo(User::class, 'organizer_id'); }
    public function chair() { return $this->belongsTo(User::class, 'chair_id'); }
    public function secretary() { return $this->belongsTo(User::class, 'secretary_id'); }
    public function facilitator() { return $this->belongsTo(User::class, 'facilitator_id'); }
    public function room() { return $this->belongsTo(MeetingRoom::class, 'room_id'); }
    public function recurrence() { return $this->belongsTo(MeetingRecurrence::class, 'recurrence_id'); }
    public function agendaItems() { return $this->hasMany(MeetingAgendaItem::class)->orderBy('position'); }
    public function invitations() { return $this->hasMany(MeetingInvitation::class); }
    public function booking() { return $this->hasOne(MeetingRoomBooking::class)->whereNull('released_at'); }
    public function statusHistory() { return $this->hasMany(MeetingStatusHistory::class)->orderBy('created_at'); }
    public function scheduleHistory() { return $this->hasMany(MeetingScheduleHistory::class)->orderByDesc('created_at'); }

    public function isConfidential(): bool { return $this->confidentiality?->code === 'confidential'; }

    /** The people who run the meeting. */
    public function isHost(User $u): bool { return in_array($u->id, array_filter([$this->organizer_id, $this->chair_id, $this->secretary_id, $this->facilitator_id]), true); }

    /**
     * What a user may see: the meetings they run or are invited to; non-confidential meetings of their departments; everything non-confidential for
     * "view all"; confidential meetings only for hosts and invitees, or for holders of the audited "view confidential" permission.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ctx = app(OrganizationContext::class);
        $viewAll = $ctx->can('meeting.view_all');
        $viewConfidential = $ctx->can('meeting.view_confidential');
        $dept = DepartmentScope::resolve($user);
        $confidentialId = LookupValue::whereNull('organization_id')->where('code', 'confidential')->whereHas('type', fn ($t) => $t->where('code', 'meeting_confidentiality'))->value('id');

        return $q->where(function (Builder $w) use ($user, $viewAll, $viewConfidential, $dept, $confidentialId) {
            $w->where('meetings.organizer_id', $user->id)->orWhere('meetings.chair_id', $user->id)->orWhere('meetings.secretary_id', $user->id)->orWhere('meetings.facilitator_id', $user->id)
              ->orWhereHas('invitations', fn ($i) => $i->where('user_id', $user->id));
            if ($viewConfidential) {
                $w->orWhereRaw('1 = 1');
            } else {
                $w->orWhere(function (Builder $open) use ($viewAll, $dept, $confidentialId) {
                    $open->where('meetings.confidentiality_id', '!=', $confidentialId);
                    $open->where(function (Builder $scope) use ($viewAll, $dept) {
                        if ($viewAll || $dept->organization) {
                            $scope->whereRaw('1 = 1');
                        } elseif ($dept->departmentIds) {
                            $scope->whereIn('meetings.department_id', $dept->departmentIds);
                        } else {
                            $scope->whereRaw('1 = 0');
                        }
                    });
                });
            }
        });
    }
}
