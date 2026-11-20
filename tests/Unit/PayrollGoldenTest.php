<?php

namespace Tests\Unit;

use App\Services\Hr\Payroll\PayrollEngine;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file tests (Guide section 10, point 9): every tests/fixtures/payroll/*.json is a worked example that must be reproduced EXACTLY.
 * The accountant's signed examples are added as files - no code change. See tests/fixtures/payroll/README.md.
 */
class PayrollGoldenTest extends TestCase
{
    private function fixtures(): array
    {
        $dir = dirname(__DIR__) . '/fixtures/payroll';
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    public function test_at_least_one_worked_example_exists(): void
    {
        $this->assertNotEmpty($this->fixtures());
    }

    public function test_every_worked_example_is_reproduced_exactly(): void
    {
        foreach ($this->fixtures() as $file) {
            $name = basename($file);
            $fx = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($fx, "$name is not valid JSON");
            $this->assertArrayHasKey('signed_off', $fx, "$name must say whether it is signed off");
            $r = PayrollEngine::calculate($fx['input']);
            foreach ($fx['expected']['totals'] as $k => $v) {
                $this->assertSame($v, $r['totals'][$k], "$name totals.$k");
            }
            foreach ($fx['expected']['lines'] as $i => $want) {
                $got = array_values(array_filter($r['lines'], fn ($l) => $l['kind'] === $want['kind'] && $l['code'] === $want['code']));
                $this->assertCount(1, $got, "$name line $i ({$want['kind']} {$want['code']})");
                $this->assertSame($want['amount'], $got[0]['amount'], "$name line {$want['code']}");
            }
        }
    }

    public function test_a_synthetic_example_is_never_marked_as_signed_off(): void
    {
        foreach ($this->fixtures() as $file) {
            $fx = json_decode((string) file_get_contents($file), true);
            if (str_starts_with(basename($file), 'synthetic-')) {
                $this->assertFalse($fx['signed_off'], basename($file) . ' is invented data and cannot be signed off');
            } else {
                $this->assertNotEmpty($fx['signed_off_by'] ?? '', basename($file) . ' needs signed_off_by');
            }
        }
    }
}
