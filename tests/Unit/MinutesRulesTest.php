<?php

namespace Tests\Unit;

use App\Services\Meetings\MinutesRules as R;
use App\Services\Meetings\MinutesTemplate as T;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7c: pure minutes rules and starting text. */
class MinutesRulesTest extends TestCase
{
    public function test_lifecycle_moves_forward_and_a_returned_draft_goes_back(): void
    {
        $this->assertTrue(R::allowed('draft', 'pending_approval'));
        $this->assertTrue(R::allowed('pending_approval', 'approved'));
        $this->assertTrue(R::allowed('pending_approval', 'draft'));
        $this->assertTrue(R::allowed('approved', 'published'));
        $this->assertTrue(R::allowed('published', 'superseded'));
        $this->assertFalse(R::allowed('draft', 'published'));
        $this->assertFalse(R::allowed('approved', 'draft'));
        $this->assertFalse(R::allowed('superseded', 'published'));
    }

    public function test_only_a_draft_is_editable_and_comments_close_after_approval(): void
    {
        $this->assertTrue(R::editable('draft'));
        foreach (['pending_approval', 'approved', 'published', 'superseded'] as $s) {
            $this->assertFalse(R::editable($s), $s);
        }
        $this->assertTrue(R::commentable('pending_approval'));
        $this->assertFalse(R::commentable('approved'));
    }

    public function test_minutes_start_only_once_the_meeting_has_started(): void
    {
        $this->assertFalse(R::canStart('draft'));
        $this->assertFalse(R::canStart('scheduled'));
        $this->assertTrue(R::canStart('in_progress'));
        $this->assertTrue(R::canStart('held'));
    }

    public function test_a_confidential_meeting_cannot_publish_to_departments_or_the_organization(): void
    {
        $this->assertSame(['participants', 'named'], array_values(R::allowedVisibilities(true)));
        $this->assertContains('organization', R::allowedVisibilities(false));
    }

    public function test_plain_length_ignores_markup(): void
    {
        $this->assertSame(5, R::plainLength('<p><b>Hello</b></p>'));
        $this->assertSame(0, R::plainLength(null));
    }

    public function test_the_starting_text_escapes_everything(): void
    {
        $l = array_fill_keys(['attendance', 'apologies', 'absent', 'none', 'chair', 'secretary', 'agenda', 'discussion', 'decision', 'resolutions', 'actions', 'closing', 'presenter'], 'L');
        $html = T::build(['title' => '<script>x</script>', 'code' => 'MTG-1', 'when' => 'today', 'where' => '', 'chair' => 'A & B', 'secretary' => null,
            'present' => ['Ann'], 'apologies' => [], 'absent' => [], 'agenda' => [['title' => 'Budget <i>', 'presenter' => null, 'expected_decision' => null]]], $l);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('Budget &lt;i&gt;', $html);
        $this->assertStringContainsString('Ann', $html);
    }
}
