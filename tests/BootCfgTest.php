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

    // -- rewriting for a host -----------------------------------------------

    /** @return array<string, mixed> */
    private static function params(array $overrides = []): array
    {
        return $overrides + [
            'prefix'           => 'http://10.0.0.2/esxi/8.0U3',
            'ks_url'           => 'http://10.0.0.2/ks.cfg?mac=00:0c:29:91:cf:eb',
            'mac'              => '00:0c:29:91:cf:eb',
            'ip'               => '10.0.0.5',
            'netmask'          => '255.255.255.0',
            'gateway'          => '10.0.0.1',
            'vlan'             => '100',
            'allow_legacy_cpu' => true,
        ];
    }

    public function testRewritesTheRepositorySampleForAHost(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../esxi/boot.cfg');
        $out = renderBootCfg($source, self::params());

        self::assertStringContainsString('ks=http://10.0.0.2/ks.cfg?mac=00:0c:29:91:cf:eb', $out);
        self::assertStringContainsString('netdevice=00:0c:29:91:cf:eb', $out);
        self::assertStringContainsString('ip=10.0.0.5 netmask=255.255.255.0 gateway=10.0.0.1', $out);
        self::assertStringContainsString('vlanid=100', $out);
        self::assertStringContainsString('allowLegacyCPU=true', $out);
    }

    /**
     * cdromBoot makes the installer look for its media on a CD-ROM that is not
     * there when booting over the network.
     */
    public function testStripsCdromBoot(): void
    {
        $out = renderBootCfg("kernelopt=runweasel cdromBoot\nkernel=b.b00\nmodules=a.gz\n", self::params());

        self::assertStringNotContainsString('cdromBoot', $out);
    }

    /**
     * The loader fetches every file from one directory whatever the ISO layout
     * was, so paths are flattened.
     */
    public function testFlattensPathSeparatorsInModuleNames(): void
    {
        $out = renderBootCfg("kernel=/b.b00\nmodules=/a.gz --- /sub/b.gz\n", self::params());
        $parsed = parseBootCfg($out);

        self::assertSame('b.b00', $parsed['kernel']);
        self::assertSame(['a.gz', 'subb.gz'], $parsed['modules']);
    }

    /**
     * The order is load-bearing: separators are stripped before any URL is
     * added, or the slashes in those URLs would be stripped too.
     */
    public function testTheAddedUrlsKeepTheirSlashes(): void
    {
        $out = renderBootCfg("kernel=/b.b00\nkernelopt=runweasel\nmodules=/a.gz\nprefix=\n", self::params());

        self::assertStringContainsString('http://10.0.0.2/ks.cfg?mac=', $out);
        self::assertStringContainsString('prefix=http://10.0.0.2/esxi/8.0U3', $out);
    }

    public function testModuleNamesSurviveTheRewriteIntact(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../esxi/boot.cfg');
        $before = parseBootCfg($source)['modules'];
        $after = parseBootCfg(renderBootCfg($source, self::params()))['modules'];

        // A dropped or mangled module is a host that boots into a panic.
        self::assertSame($before, $after);
        self::assertGreaterThan(100, count($after));
    }

    public function testTheVlanIsOmittedWhenUntagged(): void
    {
        foreach (['0', ''] as $vlan) {
            $out = renderBootCfg(
                "kernelopt=runweasel\nkernel=b.b00\nmodules=a.gz\n",
                self::params(['vlan' => $vlan])
            );

            self::assertStringNotContainsString('vlanid=', $out, "vlan '$vlan' should not be emitted");
        }
    }

    public function testLegacyCpuSupportIsOptional(): void
    {
        $out = renderBootCfg(
            "kernelopt=runweasel\nkernel=b.b00\nmodules=a.gz\n",
            self::params(['allow_legacy_cpu' => false])
        );

        self::assertStringNotContainsString('allowLegacyCPU', $out);
    }

    public function testAddressingIsOmittedForAHostWithNoIp(): void
    {
        $out = renderBootCfg(
            "kernelopt=runweasel\nkernel=b.b00\nmodules=a.gz\n",
            self::params(['ip' => ''])
        );

        // Emitting "ip= netmask=" would leave the installer configuring an
        // interface with no address rather than falling back to DHCP.
        self::assertStringNotContainsString('netdevice=', $out);
        self::assertStringNotContainsString('ip=', $out);
    }

    /**
     * A file with no kernelopt line is not what we think it is; growing one
     * would hide that rather than fix it.
     */
    public function testAFileWithNoKernelOptLineIsNotGivenOne(): void
    {
        $out = renderBootCfg("kernel=b.b00\nmodules=a.gz\n", self::params());

        self::assertStringNotContainsString('kernelopt', $out);
    }

    public function testTheKernelOptLineIsTheOnlyOneTouched(): void
    {
        $source = "bootstate=0\ntitle=Loading ESXi installer\ntimeout=5\n"
            . "kernelopt=runweasel\nkernel=b.b00\nmodules=a.gz\nbuild=8.0.3\nupdated=0\n";

        $out = renderBootCfg($source, self::params());

        self::assertStringContainsString('bootstate=0', $out);
        self::assertStringContainsString('title=Loading ESXi installer', $out);
        self::assertStringContainsString('build=8.0.3', $out);
        self::assertStringContainsString('updated=0', $out);
    }

    public function testHttpPrefixIsBuiltFromTheServerUrl(): void
    {
        self::assertSame('http://10.0.0.2/esxi/8.0U3', bootCfgHTTPPrefix('http://10.0.0.2', '8.0U3'));
        self::assertSame(
            'https://deploy.example/esxi/8.0U3',
            bootCfgHTTPPrefix('https://deploy.example/', '8.0U3'),
            'a trailing slash on the base URL must not double up'
        );
    }
}
