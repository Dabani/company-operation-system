<?php

namespace Tests\Unit;

use App\Services\Meetings\JitsiToken;
use App\Services\Meetings\OnlineRules as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7f: pure rules of online meetings and the join token. */
class OnlineMeetingRulesTest extends TestCase
{
    public function test_the_room_opens_before_the_start_and_closes_with_the_meeting(): void
    {
        $s = CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC');
        $e = $s->addHour();
        $this->assertFalse(R::joinWindowOpen('scheduled', $s->subMinutes(16), $s, $e, 15));
        $this->assertTrue(R::joinWindowOpen('scheduled', $s->subMinutes(15), $s, $e, 15));
        $this->assertTrue(R::joinWindowOpen('scheduled', $s->addMinutes(30), $s, $e, 15));
        $this->assertFalse(R::joinWindowOpen('scheduled', $e->addMinute(), $s, $e, 15));
        $this->assertTrue(R::joinWindowOpen('in_progress', $e->addHours(3), $s, $e, 15));
        foreach (['draft', 'held', 'closed', 'cancelled'] as $st) {
            $this->assertFalse(R::joinWindowOpen($st, $s, $s, $e, 15), $st);
        }
    }

    public function test_hosts_moderate_and_guests_need_permission(): void
    {
        $this->assertTrue(R::moderator(false, true, false, false));
        $this->assertTrue(R::moderator(false, false, false, true));
        $this->assertFalse(R::moderator(false, false, false, false));
        $this->assertTrue(R::guestMayJoin(true, false, 'accepted'));
        $this->assertTrue(R::guestMayJoin(true, false, null));
        $this->assertFalse(R::guestMayJoin(false, false, 'accepted'));
        $this->assertFalse(R::guestMayJoin(true, true, 'accepted'));
        $this->assertFalse(R::guestMayJoin(true, false, 'declined'));
    }

    public function test_intervention_moves_and_limits(): void
    {
        $this->assertTrue(R::allowed('requested', 'speaking'));
        $this->assertTrue(R::allowed('requested', 'withdrawn'));
        $this->assertTrue(R::allowed('speaking', 'done'));
        $this->assertFalse(R::allowed('speaking', 'withdrawn'));
        $this->assertFalse(R::allowed('done', 'requested'));
        $this->assertTrue(R::isOpen('requested'));
        $this->assertTrue(R::isOpen('speaking'));
        $this->assertFalse(R::isOpen('done'));
        $this->assertTrue(R::mayRequest('in_progress'));
        $this->assertFalse(R::mayRequest('scheduled'));
        $this->assertFalse(R::canGrant(true));
        $this->assertTrue(R::canGrant(false));
        $this->assertTrue(R::topicOk(null));
        $this->assertFalse(R::topicOk(str_repeat('x', 201)));
    }

    public function test_queue_order(): void
    {
        $q = [['id' => 'a', 'kind' => 'speak', 'at' => 10], ['id' => 'b', 'kind' => 'question', 'at' => 5], ['id' => 'c', 'kind' => 'point_of_order', 'at' => 20],
            ['id' => 'd', 'kind' => 'reply', 'at' => 15], ['id' => 'e', 'kind' => 'speak', 'at' => 5]];
        $this->assertSame(['c', 'd', 'b', 'e', 'a'], R::order($q));
    }

    public function test_room_names(): void
    {
        $this->assertTrue(R::validRoom('cops-' . str_repeat('a1', 12)));
        $this->assertFalse(R::validRoom('cops-short'));
        $this->assertFalse(R::validRoom('room-' . str_repeat('a', 24)));
        $this->assertFalse(R::validRoom('cops-' . str_repeat('A', 24)));
    }

    public function test_token_is_a_valid_hs256_jwt(): void
    {
        $t = JitsiToken::forUser('cops', 'secret', 'meet.example.org', 'cops-abc', 'Ana', 'ana@example.org', 'u1', true, 1000, 600);
        [$h, $b, $sig] = explode('.', $t);
        $this->assertSame($sig, rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$b", 'secret', true)), '+/', '-_'), '='));
        $c = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
        $this->assertSame('cops', $c['iss']);
        $this->assertSame('cops-abc', $c['room']);
        $this->assertSame(1600, $c['exp']);
        $this->assertTrue($c['context']['user']['moderator']);
        $this->assertSame('owner', $c['context']['user']['affiliation']);
        $g = JitsiToken::forUser('cops', 'secret', 'meet.example.org', 'cops-abc', 'Guest', null, 'g1', false, 1000, 600);
        $gc = json_decode(base64_decode(strtr(explode('.', $g)[1], '-_', '+/')), true);
        $this->assertFalse($gc['context']['user']['moderator']);
        $this->assertSame('member', $gc['context']['user']['affiliation']);
        $this->assertNotSame($t, $g);
    }
}
