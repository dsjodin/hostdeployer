<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Where boot.cfg and the UEFI loader sit in an extracted installation medium.
 *
 * The list used to be spelled four different ways across the tree. The
 * consequence was concrete: a medium carrying only efi/boot/boot.cfg was
 * accepted by imageLooksBootable() at upload, booted through boot.cfg.php,
 * and was at the same time reported as not installed by the admin UI and
 * refused by boot.ipxe.php with "ESXi version X is not installed".
 *
 * What matters is that every caller asks the same question, so what is tested
 * is the answer -- including the layouts that used to fall between the lists.
 */
final class BootImageLayoutTest extends TestCase
{
    private string $imageDir = '';

    protected function setUp(): void
    {
        $this->imageDir = AUTODEPLOY_ROOT . '/esxi/layout-test';
        @mkdir($this->imageDir . '/efi/boot', 0o750, true);
        @mkdir($this->imageDir . '/EFI/BOOT', 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            '/boot.cfg', '/BOOT.CFG', '/efi/boot/boot.cfg',
            '/efi/boot/bootx64.efi', '/mboot.efi', '/EFI/BOOT/BOOTX64.EFI',
        ] as $file) {
            @unlink($this->imageDir . $file);
        }
        @rmdir($this->imageDir . '/efi/boot');
        @rmdir($this->imageDir . '/efi');
        @rmdir($this->imageDir . '/EFI/BOOT');
        @rmdir($this->imageDir . '/EFI');
        @rmdir($this->imageDir);
    }

    // -----------------------------------------------------------------------
    // The lists
    // -----------------------------------------------------------------------

    public function testTheBootCfgCandidatesCoverEveryLayoutTheTreeUsedToKnow(): void
    {
        $candidates = bootCfgCandidates('/srv/autodeploy/esxi/8.0U3');

        self::assertSame([
            '/srv/autodeploy/esxi/8.0U3/boot.cfg',
            '/srv/autodeploy/esxi/8.0U3/BOOT.CFG',
            '/srv/autodeploy/esxi/8.0U3/efi/boot/boot.cfg',
        ], $candidates);
    }

    public function testATrailingSlashDoesNotDoubleUp(): void
    {
        self::assertSame(
            bootCfgCandidates('/srv/autodeploy/esxi/8.0U3'),
            bootCfgCandidates('/srv/autodeploy/esxi/8.0U3/')
        );
    }

    public function testTheLoaderCandidatesAreRelative(): void
    {
        // Relative because boot.ipxe.php turns the answer into a URL and
        // mboot.efi.php into a path.
        foreach (bootLoaderCandidates() as $candidate) {
            self::assertStringStartsWith('/', $candidate);
            self::assertStringNotContainsString('autodeploy', $candidate);
        }
    }

    // -----------------------------------------------------------------------
    // Resolving
    // -----------------------------------------------------------------------

    public function testTheRootCopyWins(): void
    {
        // Both present: the root copy is the one VMware intends to be read.
        touch($this->imageDir . '/boot.cfg');
        touch($this->imageDir . '/efi/boot/boot.cfg');

        self::assertSame($this->imageDir . '/boot.cfg', bootCfgResolve($this->imageDir));
    }

    /**
     * The layout that used to fall between the four lists: bootable, and
     * simultaneously reported as not installed.
     */
    public function testAMediumWithOnlyTheEfiCopyResolves(): void
    {
        touch($this->imageDir . '/efi/boot/boot.cfg');

        self::assertSame($this->imageDir . '/efi/boot/boot.cfg', bootCfgResolve($this->imageDir));
    }

    public function testAnUppercaseExtractionResolves(): void
    {
        touch($this->imageDir . '/BOOT.CFG');

        self::assertSame($this->imageDir . '/BOOT.CFG', bootCfgResolve($this->imageDir));
    }

    public function testAMediumWithNoBootCfgResolvesToNull(): void
    {
        self::assertNull(bootCfgResolve($this->imageDir));
    }

    public function testAMissingDirectoryResolvesToNull(): void
    {
        self::assertNull(bootCfgResolve(AUTODEPLOY_ROOT . '/esxi/not-installed'));
        self::assertNull(bootLoaderResolve(AUTODEPLOY_ROOT . '/esxi/not-installed'));
    }

    public function testTheLoaderResolvesToTheEfiCopyFirst(): void
    {
        touch($this->imageDir . '/efi/boot/bootx64.efi');
        touch($this->imageDir . '/mboot.efi');

        self::assertSame('/efi/boot/bootx64.efi', bootLoaderResolve($this->imageDir));
    }

    public function testTheLoaderResolvesAnUppercaseExtraction(): void
    {
        touch($this->imageDir . '/EFI/BOOT/BOOTX64.EFI');

        self::assertSame('/EFI/BOOT/BOOTX64.EFI', bootLoaderResolve($this->imageDir));
    }

    public function testAMediumWithNoLoaderResolvesToNull(): void
    {
        self::assertNull(bootLoaderResolve($this->imageDir));
    }

    // -----------------------------------------------------------------------
    // The callers agree
    // -----------------------------------------------------------------------

    /**
     * The bug, stated as a test: upload acceptance and UI availability have to
     * answer the same way for the same medium.
     */
    public function testUploadAcceptanceAndUiAvailabilityAgree(): void
    {
        require_once __DIR__ . '/../www/config_functions.php';

        $bootCfg = "kernel=b.b00\nkernelopt=runweasel\nmodules=jumpstrt.gz --- imgpayld.tgz\n";

        foreach (['/boot.cfg', '/BOOT.CFG', '/efi/boot/boot.cfg'] as $layout) {
            file_put_contents($this->imageDir . $layout, $bootCfg);

            $versions = getEsxiVersions(['deployment' => ['esxi_versions' => [
                'layout-test' => ['path' => $this->imageDir],
            ]]]);

            self::assertTrue(
                imageLooksBootable($this->imageDir)['ok'],
                "imageLooksBootable() rejected a medium with $layout"
            );
            self::assertTrue(
                $versions['layout-test']['available'],
                "the admin UI called a medium with $layout unavailable"
            );

            @unlink($this->imageDir . $layout);
        }
    }
}
