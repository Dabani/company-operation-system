<?php

namespace Tests\Unit;

use App\Services\Projects\TaskGraph;
use App\Services\Projects\TaskWorkflow;
use PHPUnit\Framework\TestCase;

class ProjectsPureLogicTest extends TestCase
{
    public function test_workflow_transitions_and_manager_only_moves(): void
    {
        $this->assertTrue(TaskWorkflow::allowed('todo', 'in_progress', false));
        $this->assertFalse(TaskWorkflow::allowed('todo', 'done', true));
        $this->assertFalse(TaskWorkflow::allowed('in_progress', 'done', false));     // closing without review is manager only
        $this->assertTrue(TaskWorkflow::allowed('in_progress', 'done', true));
        $this->assertTrue(TaskWorkflow::allowed('in_progress', 'in_review', false));
        $this->assertFalse(TaskWorkflow::allowed('in_review', 'done', true));         // decided by the review approval
        $this->assertFalse(TaskWorkflow::allowed('done', 'in_progress', false));
        $this->assertTrue(TaskWorkflow::allowed('done', 'in_progress', true));
        $this->assertSame(['in_progress', 'cancelled'], TaskWorkflow::targets('todo', false));
    }

    public function test_dependency_cycles(): void
    {
        $this->assertTrue(TaskGraph::wouldCycle([['b', 'a'], ['c', 'b']], 'a', 'c'));
        $this->assertFalse(TaskGraph::wouldCycle([['b', 'a']], 'c', 'a'));
        $this->assertTrue(TaskGraph::wouldCycle([], 'a', 'a'));
        $this->assertFalse(TaskGraph::wouldCycle([['b', 'a'], ['c', 'a']], 'b', 'c'));
    }
}
