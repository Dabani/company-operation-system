<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatBot;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use App\Support\Realtime;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    // ---- requester side -----------------------------------------------------------------------

    /** Open (or start) my conversation. A new one starts with the bot's greeting. */
    public function mine(Request $request)
    {
        $c = $this->myOpen($request);
        if (!$c) {
            $c = ChatConversation::create(['user_id' => $request->user()->id, 'status_id' => Lookup::id('chat_status', 'bot'), 'last_message_at' => now()]);
            [$text] = ChatBot::reply('hello', $this->locale($request));
            $this->say($c, 'bot', null, $text);
        }
        return $this->payload($c);
    }

    public function send(Request $request)
    {
        $d = $request->validate(['message' => 'required|string|max:2000']);
        $text = trim(strip_tags($d['message']));
        abort_if($text === '', 422);

        $c = $this->myOpen($request) ?? abort(404);
        $this->say($c, 'user', $request->user()->id, $text);
        $suggestion = null;

        if ($c->status->code === 'bot') {
            [$reply, $action] = ChatBot::reply($text, $this->locale($request));
            if ($reply !== '') $this->say($c, 'bot', null, $reply);
            if ($action === 'escalate') { $this->escalateConversation($c, $request); } elseif ($action === 'suggest_case') { $suggestion = 'suggest_case'; }
        } elseif ($c->status->code === 'waiting_agent') {
            // already queued for an agent: nothing else to do
        }

        $c->update(['last_message_at' => now()]);
        Realtime::emit(new ChatMessageSent($c));
        return $this->payload($c->refresh(), $suggestion);
    }

    public function escalate(Request $request)
    {
        $c = $this->myOpen($request) ?? abort(404);
        if ($c->status->code === 'bot') $this->escalateConversation($c, $request);
        Realtime::emit(new ChatMessageSent($c));
        return $this->payload($c->refresh());
    }

    public function close(Request $request)
    {
        $c = $this->myOpen($request) ?? abort(404);
        $c->update(['status_id' => Lookup::id('chat_status', 'closed')]);
        Realtime::emit(new ChatMessageSent($c));
        return response()->noContent();
    }

    // ---- agent side ---------------------------------------------------------------------------

    public function agentIndex(Request $request)
    {
        $closed = $request->query('status') === 'closed';
        $rows = ChatConversation::with(['user', 'agent', 'status.translations'])
            ->whereHas('status', fn ($s) => $closed ? $s->where('code', 'closed') : $s->where('code', '!=', 'closed'))
            ->where(fn ($q) => $closed ? $q : $q->whereHas('status', fn ($s) => $s->whereIn('code', ['waiting_agent', 'with_agent'])))
            ->orderByDesc('last_message_at')->limit(60)->get();

        return response()->json(['data' => $rows->map(function ($c) {
            $last = $c->messages()->latest('id')->first();
            return [
                'id' => $c->id, 'status' => $c->status->code, 'user' => $c->user->name, 'agent' => $c->agent?->name,
                'preview' => $last ? mb_substr($last->body, 0, 80) : '', 'last_message_at' => $c->last_message_at?->toIso8601String(),
            ];
        })]);
    }

    public function agentShow(string $id)
    {
        return $this->payload(ChatConversation::with('user')->findOrFail($id), null, true);
    }

    public function agentSend(Request $request, string $id)
    {
        $d = $request->validate(['message' => 'required|string|max:2000']);
        $c = ChatConversation::findOrFail($id);
        abort_if($c->status->code === 'closed', 422);

        $this->say($c, 'agent', $request->user()->id, trim(strip_tags($d['message'])));
        $c->update(['status_id' => Lookup::id('chat_status', 'with_agent'), 'agent_id' => $c->agent_id ?? $request->user()->id, 'last_message_at' => now()]);
        Realtime::emit(new ChatMessageSent($c));
        return $this->payload($c->refresh(), null, true);
    }

    public function agentClose(string $id)
    {
        $c = ChatConversation::findOrFail($id);
        $c->update(['status_id' => Lookup::id('chat_status', 'closed')]);
        Realtime::emit(new ChatMessageSent($c));
        return response()->noContent();
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function locale(Request $request): string
    {
        return $request->user()->language?->code ?? app()->getLocale();
    }

    private function myOpen(Request $request): ?ChatConversation
    {
        return ChatConversation::with('status')->where('user_id', $request->user()->id)
            ->whereHas('status', fn ($s) => $s->where('code', '!=', 'closed'))->latest('created_at')->first();
    }

    private function say(ChatConversation $c, string $sender, ?string $userId, string $text): ChatMessage
    {
        return ChatMessage::create(['conversation_id' => $c->id, 'sender_type_id' => Lookup::id('chat_sender', $sender), 'sender_id' => $userId, 'body' => $text]);
    }

    private function escalateConversation(ChatConversation $c, Request $request): void
    {
        $c->update(['status_id' => Lookup::id('chat_status', 'waiting_agent')]);
        $this->say($c, 'bot', null, __('notify.chat_escalated', [], $this->locale($request)));
        $agents = Notifier::usersWithPermission($this->ctx->organizationId(), 'chat.moderate')->reject(fn ($u) => $u->id === $request->user()->id);
        Notifier::send($agents, 'chat_waiting', ['user' => $request->user()->name], null, ['in_app'], '/support/chats', $this->ctx->organizationId());
    }

    private function payload(ChatConversation $c, ?string $suggestion = null, bool $agentView = false)
    {
        $c->load(['status', 'agent', 'user']);
        $msgs = ChatMessage::with(['senderType', 'sender'])->where('conversation_id', $c->id)->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $me = request()->user()->id;

        return response()->json(['data' => [
            'id' => $c->id, 'status' => $c->status->code, 'agent' => $c->agent?->name, 'user' => $c->user->name, 'suggestion' => $suggestion,
            'messages' => $msgs->map(fn ($m) => [
                'id' => $m->id, 'sender' => $m->senderType->code, 'name' => $m->sender?->name, 'body' => $m->body,
                'mine' => $m->sender_id === $me, 'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]]);
    }
}
