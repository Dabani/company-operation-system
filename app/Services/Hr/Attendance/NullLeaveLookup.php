<?php

namespace App\Services\Hr\Attendance;

/** Default until the leave module exists: no approved leave. */
class NullLeaveLookup implements LeaveLookup
{
    public function onApprovedLeave(string $employeeId, string $date): bool { return false; }
}
