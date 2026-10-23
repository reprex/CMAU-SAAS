<?php

namespace Tests\Unit;

use App\Support\PhpVersionGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhpVersionGuardTest extends TestCase
{
    public function test_patched_versions_are_allowed(): void
    {
        $this->assertTrue(PhpVersionGuard::isSupported('8.4.0', '8.4.27'));
        $this->assertTrue(PhpVersionGuard::isSupported('8.4.0', '8.5.12'));
    }

    public function test_old_versions_are_refused(): void
    {
        $this->assertFalse(PhpVersionGuard::isSupported('8.4.0', '8.1.33'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MultiPHP Manager');
        PhpVersionGuard::check('8.4.0', '8.1.33');
    }
}
