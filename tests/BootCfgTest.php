<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * boot.cfg drives what the installer loads. A dropped module is a host that
 * boots into a kernel panic, which is exactly the sort of failure that only
 * shows up on hardware at the worst moment.
 */
final class BootCfgTest extends TestCase
{
    public function testParsesTheRepositorySampleBootCfg(): void
    {
        $contents = file_get_contents(__DIR__ . '/../esxi/boot.cfg');
        self::assertIsString($contents);

        $parsed = parseBootCfg($contents);

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame('runweasel', $parsed['kernelopt']);
        self::assertSame('/esxi/8.0U3', $parsed['prefix']);
        self::assertGreaterThan(100, count($parsed['modules']), 'ESXi 8 ships well over 100 modules');
        self::assertSame('jumpstrt.gz', $parsed['modules'][0]);
        self::assertContains('imgpayld.tgz', $parsed['modules']);
        self::assertTrue(bootCfgIsUsable($parsed));
    }

    public function testSkipsCommentsAndBlankLines(): void
    {
        $parsed = parseBootCfg("# a comment\n\n   \nkernel=b.b00\nmodules=a.gz\n");

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame(['a.gz'], $parsed['modules']);
    }

    public function testIgnoresUnknownKeysRatherThanFailing(): void
    {
        $parsed = parseBootCfg("bootstate=0\ntitle=Loading\ntimeout=5\nkernel=b.b00\nmodules=a.gz\nbuild=8.0.3\n");

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame(['a.gz'], $parsed['modules']);
    }

    /**
     * VMware ships "kernelopt"; hand-edited files in this repo have used
     * "kernelopts". Both spellings have to work or the command line vanishes.
     */
    public function testAcceptsBothKernelOptSpellings(): void
    {
        self::assertSame('runweasel', parseBootCfg("kernelopt=runweasel\n")['kernelopt']);
        self::assertSame('runweasel', parseBootCfg("kernelopts=runweasel\n")['kernelopt']);
    }

    public function testToleratesWhitespaceAroundTheSeparator(): void
    {
        $parsed = parseBootCfg("kernel = b.b00\nmodules = a.gz --- b.gz\n");

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame(['a.gz', 'b.gz'], $parsed['modules']);
    }

    public function testHandlesCarriageReturns(): void
    {
        $parsed = parseBootCfg("kernel=b.b00\r\nmodules=a.gz --- b.gz\r\n");

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame(['a.gz', 'b.gz'], $parsed['modules']);
    }

    public function testDropsEmptyModuleEntries(): void
    {
        $parsed = parseBootCfg("kernel=b.b00\nmodules=a.gz ---  --- b.gz ---\n");

        self::assertSame(['a.gz', 'b.gz'], $parsed['modules']);
    }

    public function testModuleListIsAListNotASparseArray(): void
    {
        // array_filter preserves keys; a sparse array would silently break
        // any caller that assumes $modules[0] exists.
        $parsed = parseBootCfg("kernel=b.b00\nmodules= --- a.gz\n");

        self::assertSame([0], array_keys($parsed['modules']));
    }

    public function testAValueContainingAnEqualsSignSurvives(): void
    {
        self::assertSame(
            'runweasel ks=http://example/ks.cfg',
            parseBootCfg("kernelopt=runweasel ks=http://example/ks.cfg\n")['kernelopt']
        );
    }

    public function testUnusableWhenTheKernelIsMissing(): void
    {
        self::assertFalse(bootCfgIsUsable(parseBootCfg("modules=a.gz\n")));
    }

    public function testUnusableWhenThereAreNoModules(): void
    {
        self::assertFalse(bootCfgIsUsable(parseBootCfg("kernel=b.b00\n")));
    }

    public function testUnusableWhenTheFileIsEmpty(): void
    {
        self::assertFalse(bootCfgIsUsable(parseBootCfg('')));
    }

    public function testStripsAPackagedKickstartOption(): void
    {
        self::assertSame(
            'runweasel',
            stripKickstartOption('runweasel ks=http://old-server/ks.cfg')
        );
    }

    public function testLeavesACommandLineWithoutAKickstartAlone(): void
    {
        self::assertSame('runweasel', stripKickstartOption('runweasel'));
        self::assertSame('', stripKickstartOption(''));
    }

    public function testDoesNotStripAnOptionMerelyEndingInKs(): void
    {
        self::assertSame(
            'noks=keepme',
            stripKickstartOption('noks=keepme'),
            'the word boundary must not match a suffix'
        );
    }
}
