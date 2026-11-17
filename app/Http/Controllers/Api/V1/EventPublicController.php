<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Services\Events\EventRules;
use App\Services\Events\EventService;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;

/**
 * Registration and ticket pages for people without an account. Unauthenticated and rate limited: the event link is the credential for signing up,
 * the personal ticket link for viewing or cancelling one registration. Only what a visitor needs is shown (never the list of other people).
 */
class EventPublicController extends Controller
{
    public function __construct(private EventService $svc) {}

    private function event(string $token): Event
    {
        abort_unless(EventRules::ticketValid($token), 404);
        $e = Event::withoutGlobalScopes()->with(['status', 'type'])->where('public_token', $token)->first();
        abort_unless($e && $e->allow_guests && $e->status->code !== 'draft', 404);
        app(OrganizationContext::class)->resolveForOrganization(Organization::findOrFail($e->organization_id));
        return $e;
    }

    private function ticket(string $token): EventRegistration
    {
        abort_unless(EventRules::ticketValid($token), 404);
        $r = EventRegistration::withoutGlobalScopes()->with('status')->where('ticket_token', $token)->whereNull('user_id')->first();
        abort_unless($r, 404);
        app(OrganizationContext::class)->resolveForOrganization(Organization::findOrFail($r->organization_id));
        return $r;
    }

    private function eventShape(Event $e): array
    {
        $stats = $this->svc->stats($e);
        return ['title' => $e->title, 'description' => $e->description, 'type' => $e->type->code, 'status' => $e->status->code, 'timezone' => AttendanceService::tz($e->organization_id),
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'venue' => $e->venue, 'online_url' => $e->online_url, 'free_seats' => $stats['free_seats'],
            'waitlist_enabled' => $e->waitlist_enabled, 'can_register' => EventRules::registrationOpen($e->status->code, time(), $e->registration_opens_at?->getTimestamp(), $e->registration_closes_at?->getTimestamp())
                && ($stats['free_seats'] === null || $stats['free_seats'] > 0 || $e->waitlist_enabled)];
    }

    private function ticketShape(EventRegistration $r): array
    {
        $e = Event::withoutGlobalScopes()->with(['status', 'type'])->findOrFail($r->event_id);
        return ['data' => ['event' => $this->eventShape($e), 'name' => $r->guest_name, 'status' => $r->status->code, 'ticket' => $r->status->code === 'cancelled' ? null : $r->ticket_token,
            'can_cancel' => $r->status->code !== 'cancelled' && !in_array($e->status->code, ['in_progress', 'completed', 'cancelled'], true)]];
    }

    public function show(string $token) { return ['data' => $this->eventShape($this->event($token))]; }

    public function register(Request $request, string $token)
    {
        $e = $this->event($token);
        $d = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:190', 'phone' => 'nullable|string|max:40', 'organization' => 'nullable|string|max:150']);
        $r = $this->svc->register($e, null, $d, null);
        return response($this->ticketShape($r->load('status')), 201);
    }

    public function ticketShow(string $ticket) { return $this->ticketShape($this->ticket($ticket)); }

    public function ticketCancel(string $ticket)
    {
        $r = $this->ticket($ticket);
        $e = Event::withoutGlobalScopes()->with('status')->findOrFail($r->event_id);
        $this->svc->cancelRegistration($r, $e, null);
        return $this->ticketShape($r->refresh()->load('status'));
    }
}
