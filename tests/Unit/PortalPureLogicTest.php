<?php

namespace Tests\Unit;

use App\Services\Portal\PortalAccess;
use App\Services\Portal\Totp;
use PHPUnit\Framework\TestCase;

class PortalPureLogicTest extends TestCase
{
    public function test_totp_matches_the_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $t => $code) {
            $this->assertSame($code, Totp::code($secret, $t), "time $t");
        }
    }

    public function test_verify_window_format_and_step(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame(1, Totp::verify($secret, '287082', 59));
        $this->assertSame(1, Totp::verify($secret, '287 082', 59));
        $this->assertSame(1, Totp::verify($secret, '287082', 89));             // one step late is tolerated
        $this->assertNull(Totp::verify($secret, '287082', 149));              // three steps late is not
        $this->assertNull(Totp::verify($secret, '12345', 59));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
    }

    public function test_progress_percent_rounds_and_handles_no_tasks(): void
    {
        $this->assertSame(0, PortalAccess::percent(0, 0));
        $this->assertSame(33, PortalAccess::percent(1, 3));
        $this->assertSame(67, PortalAccess::percent(2, 3));
        $this->assertSame(100, PortalAccess::percent(5, 5));
    }
}
