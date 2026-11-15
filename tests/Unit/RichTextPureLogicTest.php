<?php

namespace Tests\Unit;

use App\Support\Html;
use PHPUnit\Framework\TestCase;

class RichTextPureLogicTest extends TestCase
{
    public function test_plain_text_ignores_tags_and_decodes_entities(): void
    {
        $this->assertSame('ab', Html::plain('<p>ab</p>'));                                   // 2 characters, not 9
        $this->assertSame('Forgot to clock in', Html::plain('<p>Forgot to <strong>clock</strong> in</p>'));
        $this->assertSame('one two', Html::plain('<ul><li>one</li><li>two</li></ul>'));
        $this->assertSame('Tom & Jerry', Html::plain('<p>Tom &amp; Jerry</p>'));
        $this->assertSame('', Html::plain('<p> </p>'));
        $this->assertSame('', Html::plain(null));
        $this->assertSame('plain text', Html::plain('plain text'));
    }

    public function test_reason_is_rich_only_on_the_configured_routes(): void
    {
        $config = require __DIR__ . '/../../config/ops.php';
        $patterns = $config['rich_text_route_fields']['reason'];
        $matches = fn (string $path) => (bool) array_filter($patterns, fn ($p) => fnmatch($p, $path));
        foreach (['api/v1/time-entries', 'api/v1/time-entries/abc/adjust', 'api/v1/attendance/corrections', 'api/v1/attendance/periods/2026-11/reopen',
            'api/v1/employees/abc/employment-changes', 'api/v1/applicants/abc/reject', 'api/v1/applicants/abc/extend-retention'] as $rich) {
            $this->assertTrue($matches($rich), $rich);
        }
        foreach (['api/v1/users/abc/status', 'api/v1/users/bulk', 'api/v1/access-grants/abc/revoke', 'api/v1/invoices/abc/credit-notes', 'api/v1/records/abc/hold'] as $plain) {
            $this->assertFalse($matches($plain), $plain);
        }
    }
}
