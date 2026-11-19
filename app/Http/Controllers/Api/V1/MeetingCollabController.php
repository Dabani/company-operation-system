<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingChatMessage;
use App\Models\MeetingPoll;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MeetingCollabService;
use App\Services\Meetings\OnlineMeetingService;
use Illuminate\Http\Request;

/** Chat and polls of an online meeting, for account holders. Visibility of the meeting is MeetingAccess (404 when not visible). */
class MeetingCollabController extends Controller
{
    public function __construct(private MeetingAccess $access, private MeetingCollabService $svc, private OnlineMeetingService $online) {}

    private function meeting(Request $r, string $id): Meeting
    {
        return $this->access->find($r->user(), $id, ['status', 'confidentiality']);
    }

    private function participantOnly(Request $r, Meeting $m): void
    {
        abort_unless($m->online_room && $this->access->isParticipant($r->user(), $m), 403, 'forbidden');
    }

    private function moderator(Request $r, Meeting $m): bool
    {
        return $this->online->isModerator($m, $r->user()) || $this->access->canChair($r->user(), $m);
    }

    public function chat(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $this->participantOnly($request, $m);
        $msgs = $this->svc->messages($m, $request->user(), null, $request->query('after'));
        return ['data' => $msgs, 'meta' => ['last_id' => $msgs ? end($msgs)['id'] : $request->query('after'), 'can_post' => $this->svc->participates($m, $request->user(), null) && $this->online->windowOpen($m),
            'can_remove' => $this->moderator($request, $m)]];
    }

    public function post(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $d = $request->validate(['body' => 'required|string|max:1000']);
        $this->svc->post($m, $request->user(), null, $d['body']);
        return $this->chat($request, $id);
    }

    public function remove(Request $request, string $messageId)
    {
        $msg = MeetingChatMessage::findOrFail($messageId);
        $this->meeting($request, $msg->meeting_id);
        $this->svc->removeMessage($messageId, $request->user());
        return response()->noContent();
    }

    public function polls(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $this->participantOnly($request, $m);
        $mod = $this->moderator($request, $m);
        return ['data' => $this->svc->polls($m, $request->user(), null, $mod), 'meta' => ['can_create' => $mod && $m->status->code === 'in_progress']];
    }

    public function createPoll(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $d = $request->validate(['question' => 'required|string|max:300', 'options' => 'required|array|min:2|max:10', 'options.*' => 'required|string|max:150', 'multiple' => 'sometimes|boolean', 'max_choices' => 'nullable|integer|min:0|max:10',
            'anonymous' => 'sometimes|boolean', 'guests_vote' => 'sometimes|boolean', 'agenda_item_id' => 'nullable|string']);
        $this->svc->createPoll($m, $request->user(), $d['question'], $d['options'], $d);
        return $this->polls($request, $id);
    }

    private function pollMeeting(Request $request, string $pollId): array
    {
        $p = MeetingPoll::findOrFail($pollId);
        return [$p, $this->meeting($request, $p->meeting_id)];
    }

    public function vote(Request $request, string $pollId)
    {
        [$p, $m] = $this->pollMeeting($request, $pollId);
        $d = $request->validate(['option_ids' => 'required|array|min:1|max:10', 'option_ids.*' => 'string']);
        $this->svc->vote($p, $request->user(), null, $d['option_ids']);
        return $this->polls($request, $m->id);
    }

    public function closePoll(Request $request, string $pollId)
    {
        [$p, $m] = $this->pollMeeting($request, $pollId);
        $this->svc->closePoll($pollId, $request->user());
        return $this->polls($request, $m->id);
    }
}
