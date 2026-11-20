<?php

namespace App\Services\Hr\Payroll;

use RuntimeException;

/** A layout cannot be produced faithfully (a value does not fit its column, an unknown placeholder, a lossy encoding): the export is refused, never silently truncated or altered. */
class ExportFormatError extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail = '')
    {
        parent::__construct("export format error: $reason" . ($detail !== '' ? " ($detail)" : ''));
    }
}
