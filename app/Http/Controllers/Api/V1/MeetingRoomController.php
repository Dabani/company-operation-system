<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MeetingRoom;
use App\Models\MeetingRoomBooking;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Meetings\RoomBookingService;
use App\Services\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The room catalogue and a room's busy periods. Busy periods show times only: who holds a room may be confidential. */
class MeetingRoomController extends Controller
{
    private function shape(MeetingRoom $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'location_id' => $r->location_id, 'location' => $r->location?->name, 'capacity' => $r->capacity, 'equipment' => $r->equipment, 'is_active' => $r->is_active];
    }

    private function rules(?MeetingRoom $r = null): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        return [
            'name' => [$r ? 'sometimes' : 'required', 'string', 'max:150', Rule::unique('meeting_rooms', 'name')->where('organization_id', $org)->ignore($r?->id)],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('organization_id', $org)],
            'capacity' => 'nullable|integer|min:1|max:5000', 'equipment' => 'nullable|string|max:1000', 'is_active' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request)
    {
        $q = MeetingRoom::with('location')->orderBy('name');
        if (!$request->boolean('all')) {
            $q->where('is_active', true);
        }
        return ['data' => $q->get()->map(fn ($r) => $this->shape($r))];
    }

    public function store(Request $request)
    {
        $r = MeetingRoom::create($request->validate($this->rules()));
        AuditLogger::log('meeting_room.created', $r, [], ['name' => $r->name]);
        return response()->json(['data' => $this->shape($r->load('location'))], 201);
    }

    public function update(Request $request, string $id)
    {
        $r = MeetingRoom::findOrFail($id);
        $d = $request->validate($this->rules($r));
        $old = $r->only(array_keys($d));
        $r->update($d);
        AuditLogger::log('meeting_room.updated', $r, $old, $d);
        return ['data' => $this->shape($r->load('location'))];
    }

    /** A room with future bookings cannot be removed (deactivate it instead); one never used can. */
    public function destroy(string $id)
    {
        $r = MeetingRoom::findOrFail($id);
        abort_if(MeetingRoomBooking::where('room_id', $r->id)->exists(), 422, __('meetings.room_in_use'));
        AuditLogger::log('meeting_room.deleted', $r, ['name' => $r->name], []);
        $r->delete();
        return response()->noContent();
    }

    public function availability(Request $request, string $id, RoomBookingService $rooms)
    {
        $r = MeetingRoom::findOrFail($id);
        $d = $request->validate(['from' => 'required|date', 'to' => 'required|date|after:from']);
        $from = CarbonImmutable::parse($d['from']);
        $to = CarbonImmutable::parse($d['to']);
        abort_if($from->diffInDays($to) > 62, 422, __('meetings.bad_period'));
        $tz = AttendanceService::tz($r->organization_id);
        return ['data' => ['room' => $this->shape($r), 'timezone' => $tz,
            'busy' => $rooms->busy($r->id, $from, $to)->map(fn ($b) => ['starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String()])->values()]];
    }
}
