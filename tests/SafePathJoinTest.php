<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * safePathJoin() is what stands between the template editor and arbitrary
 * file writes. CODE-REVIEW.md section 2.1 describes what its absence cost:
 * a logged-in operator could write ../www/shell.php and get a webshell.
 */
final class SafePathJoinTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/hostdeployer-pathtest-' . getmypid();
        @mkdir($this->base . '/sub', 0o750, true);
        file_put_contents($this->base . '/existing.cfg', 'x');
        file_put_contents($this->base . '/sub/nested.cfg', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/sub/nested.cfg');
        @unlink($this->base . '/existing.cfg');
        @rmdir($this->base . '/sub');
        @rmdir($this->base);
    }

    public function testAcceptsAPlainNameInTheBaseDirectory(): void
    {
        $path = safePathJoin($this->base, 'existing.cfg', true);

        self::assertNotNull($path);
        self::assertSame(realpath($this->base) . '/existing.cfg', $path);
    }

    public function testAcceptsASubdirectory(): void
    {
        self::assertNotNull(safePathJoin($this->base, 'sub/nested.cfg', true));
    }

    public function testAcceptsANameThatDoesNotExistYetWhenNotRequired(): void
    {
        $path = safePathJoin($this->base, 'brand-new.cfg');

        self::assertNotNull($path);
        self::assertSame(realpath($this->base) . '/brand-new.cfg', $path);
    }

    public function testRejectsANameThatDoesNotExistWhenRequired(): void
    {
        self::assertNull(safePathJoin($this->base, 'brand-new.cfg', true));
    }

    /** @return array<string, array{string}> */
    public static function traversalProvider(): array
    {
        return [
            'parent'              => ['../escaped.php'],
            'parent twice'        => ['../../etc/passwd'],
            'traversal mid-path'  => ['sub/../../escaped.php'],
            'absolute posix'      => ['/etc/passwd'],
            'absolute windows'    => ['\\windows\\system32'],
            'backslash traversal' => ['..\\escaped.php'],
            'null byte'           => ["ok.cfg\0.php"],
            'empty'               => [''],
        ];
    }

    #[DataProvider('traversalProvider')]
    public function testRejectsEscapeAttempts(string $userPath): void
    {
        self::assertNull(safePathJoin($this->base, $userPath));
    }

    public function testRejectsAMissingBaseDirectory(): void
    {
        self::assertNull(safePathJoin($this->base . '/does-not-exist', 'x.cfg'));
    }
}
