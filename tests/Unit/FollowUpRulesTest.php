<?php

namespace Tests\Unit;

use App\Services\Meetings\FollowUpRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7d: pure rules for resolutions and action items. */
class FollowUpRulesTest extends TestCase
{
    public function test_lifecycles(): void
    {
        $this->assertTrue(R::allowed('action', 'open', 'done'));
        $this->assertTrue(R::allowed('action', 'done', 'closed'));
        $this->assertFalse(R::allowed('action', 'closed', 'open'));
        $this->assertFalse(R::allowed('action', 'open', 'closed'));
        $this->assertTrue(R::allowed('resolution', 'completed', 'open'));
        $this->assertFalse(R::allowed('resolution', 'cancelled', 'open'));
    }

    public function test_recording_needs_a_started_meeting(): void
    {
        $this->assertFalse(R::canRecord('scheduled'));
        $this->assertFalse(R::canRecord('cancelled'));
        $this->assertTrue(R::canRecord('in_progress'));
        $this->assertTrue(R::canRecord('closed'));
    }

    public function test_reasons_and_evidence(): void
    {
        $this->assertTrue(R::needsReason('action', 'open', 'cancelled'));
        $this->assertTrue(R::needsReason('action', 'done', 'in_progress'));
        $this->assertTrue(R::needsReason('resolution', 'completed', 'open'));
        $this->assertFalse(R::needsReason('action', 'open', 'in_progress'));
        $this->assertFalse(R::evidenceOk(' ab ', 0));
        $this->assertTrue(R::evidenceOk('Report sent', 0));
        $this->assertTrue(R::evidenceOk(null, 1));
    }

    public function test_the_owner_reports_progress_and_someone_else_closes(): void
    {
        $this->assertTrue(R::canMove('action', 'open', 'done', true, false));
        $this->assertFalse(R::canMove('action', 'open', 'cancelled', true, false));
        $this->assertFalse(R::canMove('action', 'done', 'closed', true, false));
        $this->assertFalse(R::canMove('action', 'done', 'closed', true, true), 'owner cannot close their own action');
        $this->assertTrue(R::canMove('action', 'done', 'closed', false, true));
        $this->assertFalse(R::canMove('action', 'done', 'in_progress', true, false), 'only the runners reopen');
        $this->assertTrue(R::canMove('action', 'done', 'in_progress', false, true));
        $this->assertFalse(R::canMove('action', 'open', 'done', false, false));
        $this->assertTrue(R::canMove('resolution', 'open', 'completed', true, false));
    }

    public function test_overdue_and_reminders(): void
    {
        $this->assertTrue(R::isOverdue('open', '2026-11-10', '2026-11-16'));
        $this->assertFalse(R::isOverdue('open', '2026-11-16', '2026-11-16'));
        $this->assertFalse(R::isOverdue('done', '2026-11-10', '2026-11-16'));
        $this->assertFalse(R::isOverdue('open', null, '2026-11-16'));
        $this->assertSame(6, R::daysOverdue('2026-11-10', '2026-11-16'));
        $this->assertTrue(R::dueSoon('open', '2026-11-18', '2026-11-16', 2));
        $this->assertFalse(R::dueSoon('open', '2026-11-19', '2026-11-16', 2));
        $this->assertFalse(R::dueSoon('open', '2026-11-15', '2026-11-16', 2));
        $this->assertTrue(R::overdueDue(null, '2026-11-16', 3));
        $this->assertFalse(R::overdueDue('2026-11-15', '2026-11-16', 3));
        $this->assertTrue(R::overdueDue('2026-11-13', '2026-11-16', 3));
    }
}
