<?php

namespace App\Services;

/** Hook for malware scanning (e.g. ClamAV). Bind a real implementation in AppServiceProvider; default accepts everything. */
interface FileScanner
{
    public function isClean(string $absolutePath): bool;
}
