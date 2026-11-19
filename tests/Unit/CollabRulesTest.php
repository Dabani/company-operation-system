<?php

namespace Tests\Unit;

use App\Services\Meetings\CollabRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7g: pure rules of chat and polls. */
class CollabRulesTest extends TestCase
{
    public function test_messages_and_questions(): void
    {
        $this->assertFalse(R::messageOk('   '));
        $this->assertFalse(R::messageOk(null));
        $this->assertTrue(R::messageOk(' hello '));
        $this->assertTrue(R::messageOk(str_repeat('a', 1000)));
        $this->assertFalse(R::messageOk(str_repeat('a', 1001)));
        $this->assertFalse(R::questionOk('ok'));
        $this->assertTrue(R::questionOk('Approve the budget?'));
        $this->assertFalse(R::questionOk(str_repeat('q', 301)));
    }

    public function test_options(): void
    {
        $this->assertTrue(R::optionsOk(['Yes', 'No']));
        $this->assertFalse(R::optionsOk(['Yes']));
        $this->assertFalse(R::optionsOk(['Yes', 'yes ']));
        $this->assertFalse(R::optionsOk(['Yes', '  ']));
        $this->assertFalse(R::optionsOk(array_map(fn ($i) => "o$i", range(1, 11))));
        $this->assertTrue(R::optionsOk(array_map(fn ($i) => "o$i", range(1, 10))));
        $this->assertFalse(R::optionsOk(['Yes', str_repeat('x', 151)]));
    }

    public function test_choices(): void
    {
        $this->assertTrue(R::choicesOk(1, false, 0, 3));
        $this->assertFalse(R::choicesOk(2, false, 0, 3));
        $this->assertFalse(R::choicesOk(0, true, 0, 3));
        $this->assertTrue(R::choicesOk(3, true, 0, 3));
        $this->assertFalse(R::choicesOk(3, true, 2, 3));
        $this->assertTrue(R::choicesOk(2, true, 2, 3));
        $this->assertFalse(R::choicesOk(4, true, 0, 3));
    }

    public function test_moves_and_visibility(): void
    {
        $this->assertTrue(R::allowedMove('open', 'closed'));
        $this->assertFalse(R::allowedMove('closed', 'open'));
        $this->assertTrue(R::showResults('open', true));
        $this->assertFalse(R::showResults('open', false));
        $this->assertTrue(R::showResults('closed', false));
    }

    public function test_tally_percent_leaders(): void
    {
        $c = R::tally(['a', 'b', 'c'], ['a', 'b', 'a', 'x']);
        $this->assertSame(['a' => 2, 'b' => 1, 'c' => 0], $c);
        $this->assertSame(66.7, R::percent(2, 3));
        $this->assertSame(0.0, R::percent(1, 0));
        $this->assertSame(['a'], R::leaders($c));
        $this->assertSame(['a', 'b'], R::leaders(['a' => 2, 'b' => 2, 'c' => 0]));
        $this->assertSame([], R::leaders(['a' => 0, 'b' => 0]));
        $this->assertSame([], R::leaders([]));
    }
}
