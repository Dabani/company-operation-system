<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\Hr\EmploymentService;

/** Every new employee starts their history with a "hire" row. */
class EmployeeObserver
{
    public function created(Employee $e): void
    {
        app(EmploymentService::class)->recordHire($e);
    }
}
