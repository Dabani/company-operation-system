<?php

namespace Tests\Unit;

use App\Services\CodeGenerator;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function test_format_pads_to_four_digits_and_grows_naturally(): void
    {
        $this->assertSame('PRJ-0001', CodeGenerator::format('PRJ', 1));
        $this->assertSame('CLI-0042', CodeGenerator::format('CLI', 42));
        $this->assertSame('EMP-12345', CodeGenerator::format('EMP', 12345));
    }

    public function test_key_is_a_readable_slug_made_unique(): void
    {
        $taken = ['kenyan_shilling'];
        $exists = fn ($c) => in_array($c, $taken, true);
        $this->assertSame('annual_leave_minimum', CodeGenerator::key('Annual leave minimum', $exists));
        $this->assertSame('kenyan_shilling_2', CodeGenerator::key('Kenyan shilling', $exists));
        $this->assertSame('value', CodeGenerator::key('???', $exists));
    }
}
