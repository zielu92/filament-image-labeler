<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use Zielu92\FilamentImageLabeler\Support\EntityUrlGuard;

class EntityUrlGuardTest extends TestCase
{
    public function test_relative_paths_are_safe(): void
    {
        foreach (['/people/1', '/x?a=1', '/x#frag', '/a/b/c', '?q=1', '#frag', '/'] as $url) {
            $this->assertTrue(EntityUrlGuard::isSafe($url), $url);
        }
    }

    public function test_plain_http_urls_are_safe_case_insensitively(): void
    {
        foreach (['http://example.com/a', 'https://example.com', 'HTTPS://Example.COM/x'] as $url) {
            $this->assertTrue(EntityUrlGuard::isSafe($url), $url);
        }
    }

    public function test_dangerous_schemes_are_rejected(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            '  javascript:alert(1)',
            'javascript:alert(1)' . "\n",
        ] as $url) {
            $this->assertFalse(EntityUrlGuard::isSafe($url), $url);
        }
    }

    public function test_protocol_relative_hosts_are_rejected(): void
    {
        foreach (['//evil.example/x', '/\\evil.example/x'] as $url) {
            $this->assertFalse(EntityUrlGuard::isSafe($url), $url);
        }
    }

    public function test_empty_null_and_control_characters_are_rejected(): void
    {
        $this->assertFalse(EntityUrlGuard::isSafe(null));
        $this->assertFalse(EntityUrlGuard::isSafe(''));
        $this->assertFalse(EntityUrlGuard::isSafe('/ok' . "\x00" . 'tail'));
        $this->assertFalse(EntityUrlGuard::isSafe('http://'));
    }
}
