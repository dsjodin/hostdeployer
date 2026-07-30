<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Image installation. Extraction itself shells out to whichever tool is
 * present, so what is tested here is everything around it: the validation that
 * decides whether an upload is accepted, and the checks that decide whether an
 * extracted directory is allowed to become a bootable version.
 */
final class ImagesTest extends TestCase
{
    protected function setUp(): void
    {
        file_put_contents(AUTODEPLOY_GLOBAL_CONFIG, json_encode([
            'deployment' => ['esxi_versions' => [], 'default_version' => ''],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_GLOBAL_CONFIG);
        @unlink(AUTODEPLOY_GLOBAL_CONFIG . '.lock');

        foreach (['8.0U3', '8.0U2', 'evil'] as $version) {
            imageRemoveDirectory($version);
        }
    }

    /** @return array<string, array{string}> */
    public static function validVersionProvider(): array
    {
        return [
            'update release' => ['8.0U3'],
            'dotted'         => ['8.0.3'],
            'dashed'         => ['esxi-8-0-u3'],
            'underscored'    => ['ESXi_8'],
            'digits'         => ['80'],
        ];
    }

    #[DataProvider('validVersionProvider')]
    public function testAcceptsUsableVersionNames(string $version): void
    {
        self::assertTrue(imageIsValidVersionName($version));
    }

    /**
     * The name becomes a directory and a URL path segment, so anything that
     * escapes either is a way out of the image directory.
     *
     * @return array<string, array{string}>
     */
    public static function invalidVersionProvider(): array
    {
        return [
            'empty'          => [''],
            'traversal'      => ['../etc'],
            'slash'          => ['8.0/U3'],
            'backslash'      => ['8.0\\U3'],
            'dot'            => ['.'],
            'dotdot'         => ['..'],
            'hidden'         => ['.hidden'],
            'space'          => ['8.0 U3'],
            'null byte'      => ["8.0U3\0"],
            'shell metachar' => ['8.0U3; rm -rf /'],
            'too long'       => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('invalidVersionProvider')]
    public function testRejectsUnusableVersionNames(string $version): void
    {
        self::assertFalse(imageIsValidVersionName($version));
        self::assertNull(imageDirectory($version), 'an unusable name resolves to no directory');
    }

    /**
     * The same pattern www/boot.ipxe.php enforces before building the image
     * URL. A name accepted here but rejected there would upload and never boot.
     */
    public function testTheNamePatternMatchesTheBootEndpoint(): void
    {
        foreach (['8.0U3', 'esxi-8', 'a_b.c-1'] as $version) {
            self::assertSame(
                (bool)preg_match('/^[A-Za-z0-9._-]+$/', $version),
                imageIsValidVersionName($version),
                "$version should be judged the same by both"
            );
        }
    }

    // -- hash verification --------------------------------------------------

    public function testVerifiesAMatchingDigest(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($file, 'pretend this is an ISO');

        try {
            self::assertTrue(imageVerifyHash($file, hash('sha256', 'pretend this is an ISO')));
            self::assertTrue(
                imageVerifyHash($file, strtoupper(hash('sha256', 'pretend this is an ISO'))),
                'the comparison is case insensitive'
            );
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsAMismatchedDigest(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($file, 'contents');

        try {
            self::assertFalse(imageVerifyHash($file, hash('sha256', 'different contents')));
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsAMalformedDigest(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($file, 'contents');

        try {
            // An empty or truncated hash must not be treated as "no hash
            // supplied, accept anything" -- that is how an unverified image
            // gets installed while the operator believes it was checked.
            foreach (['', 'abc', 'not-hex-at-all', str_repeat('z', 64)] as $bad) {
                self::assertFalse(imageVerifyHash($file, $bad), "should reject: $bad");
            }
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsAMissingFile(): void
    {
        self::assertFalse(imageVerifyHash('/nonexistent/image.iso', hash('sha256', '')));
    }

    // -- bootability --------------------------------------------------------

    public function testAnExtractedImageWithAUsableBootCfgIsAccepted(): void
    {
        $dir = $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz --- b.gz\n");

        self::assertTrue(imageLooksBootable($dir)['ok']);
    }

    public function testBootCfgUnderEfiBootIsFound(): void
    {
        $dir = (string)imageDirectory('8.0U3');
        @mkdir($dir . '/efi/boot', 0o755, true);
        file_put_contents($dir . '/efi/boot/boot.cfg', "kernel=b.b00\nmodules=a.gz\n");

        self::assertTrue(imageLooksBootable($dir)['ok']);
    }

    /**
     * This is the failure docs/bootchain.md lists as "installer starts but
     * finds no modules". Catching it at upload turns a mystery on the console
     * into a rejected file.
     */
    public function testAnImageWithNoBootCfgIsRejected(): void
    {
        $dir = (string)imageDirectory('8.0U3');
        @mkdir($dir, 0o755, true);
        file_put_contents($dir . '/README.txt', 'not installer media');

        $result = imageLooksBootable($dir);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no boot.cfg', $result['reason']);
    }

    public function testAnImageWithAnEmptyBootCfgIsRejected(): void
    {
        $dir = $this->makeImageDirectory('8.0U3', "title=Loading\ntimeout=5\n");

        $result = imageLooksBootable($dir);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no kernel or no modules', $result['reason']);
    }

    // -- registration -------------------------------------------------------

    public function testRegisteringMakesAVersionSelectable(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");

        self::assertTrue(imageRegister('8.0U3', 'ESXi 8.0 Update 3'));

        $config = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
        self::assertArrayHasKey('8.0U3', $config['deployment']['esxi_versions']);
        self::assertSame('ESXi 8.0 Update 3', $config['deployment']['esxi_versions']['8.0U3']['description']);
    }

    /**
     * A fresh appliance should be able to deploy after one upload, not one
     * upload plus a configuration edit nobody documented.
     */
    public function testTheFirstImageBecomesTheDefault(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        imageRegister('8.0U3');

        self::assertSame('8.0U3', loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG)['deployment']['default_version']);
    }

    public function testASecondImageDoesNotStealTheDefault(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        $this->makeImageDirectory('8.0U2', "kernel=b.b00\nmodules=a.gz\n");

        imageRegister('8.0U3');
        imageRegister('8.0U2');

        self::assertSame('8.0U3', loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG)['deployment']['default_version']);
    }

    /**
     * A default pointing at a version that is gone fails with a message about
     * a missing image rather than about a missing default.
     */
    public function testUnregisteringTheDefaultPromotesAnother(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        $this->makeImageDirectory('8.0U2', "kernel=b.b00\nmodules=a.gz\n");
        imageRegister('8.0U3');
        imageRegister('8.0U2');

        imageUnregister('8.0U3');

        $config = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
        self::assertSame('8.0U2', $config['deployment']['default_version']);
        self::assertArrayNotHasKey('8.0U3', $config['deployment']['esxi_versions']);
    }

    public function testUnregisteringTheLastImageLeavesNoDefault(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        imageRegister('8.0U3');

        imageUnregister('8.0U3');

        self::assertSame('', loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG)['deployment']['default_version']);
    }

    public function testListingReportsWhatIsActuallyOnDisk(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        imageRegister('8.0U3');

        // Registered, then removed by hand -- the configuration that leaves a
        // host stuck at the boot prompt.
        imageRegister('8.0U2');

        $byVersion = [];
        foreach (imageList() as $image) {
            $byVersion[$image['version']] = $image;
        }

        self::assertTrue($byVersion['8.0U3']['present']);
        self::assertTrue($byVersion['8.0U3']['bootable']);
        self::assertFalse($byVersion['8.0U2']['present'], 'a registered version with no directory is reported');
        self::assertFalse($byVersion['8.0U2']['bootable']);
    }

    // -- deletion -----------------------------------------------------------

    public function testRemovingAnImageDeletesItsTree(): void
    {
        $dir = $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");
        @mkdir($dir . '/efi/boot', 0o755, true);
        file_put_contents($dir . '/efi/boot/bootx64.efi', 'binary');

        self::assertTrue(imageRemoveDirectory('8.0U3'));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRemovingAnAbsentImageIsNotAnError(): void
    {
        self::assertTrue(imageRemoveDirectory('8.0U3'));
    }

    /**
     * A recursive delete driven by a name from a request is worth being
     * paranoid about.
     */
    public function testRemovalRefusesAnythingOutsideTheImageDirectory(): void
    {
        self::assertFalse(imageRemoveDirectory('../config'));
        self::assertFalse(imageRemoveDirectory('/etc'));
        self::assertDirectoryExists(AUTODEPLOY_CONFIG_DIR);
    }

    // -- extractor probing --------------------------------------------------

    public function testTheExtractorTableIsOrderedAndComplete(): void
    {
        $candidates = imageExtractorCandidates();

        self::assertArrayHasKey('bsdtar', $candidates);
        self::assertSame('bsdtar', array_key_first($candidates), 'libarchive reads both ISO9660 and UDF');

        foreach ($candidates as $name => $template) {
            self::assertStringContainsString('%', $template, "$name takes the paths as arguments");
        }
    }

    public function testInstallFailsClearlyWithNoExtractorInstalled(): void
    {
        // Nothing to extract with, or nothing to extract: either way the
        // failure has to name which, and leave no directory behind.
        $result = imageInstall('/nonexistent.iso', '8.0U3');

        self::assertFalse($result['success']);
        self::assertNotSame('', $result['error']);
        self::assertDirectoryDoesNotExist((string)imageDirectory('8.0U3'));
    }

    public function testInstallRefusesToOverwriteAnInstalledVersion(): void
    {
        $this->makeImageDirectory('8.0U3', "kernel=b.b00\nmodules=a.gz\n");

        $result = imageInstall('/nonexistent.iso', '8.0U3');

        self::assertFalse($result['success']);
        self::assertStringContainsString('already installed', $result['error']);
        self::assertFileExists((string)imageDirectory('8.0U3') . '/boot.cfg', 'the existing image is untouched');
    }

    public function testInstallRejectsABadVersionNameBeforeTouchingTheDisk(): void
    {
        $result = imageInstall('/nonexistent.iso', '../escape');

        self::assertFalse($result['success']);
        self::assertStringContainsString('version name', $result['error']);
    }

    /**
     * Create an extracted image directory with the given boot.cfg.
     */
    private function makeImageDirectory(string $version, string $bootCfg): string
    {
        $dir = (string)imageDirectory($version);
        @mkdir($dir, 0o755, true);
        file_put_contents($dir . '/boot.cfg', $bootCfg);

        return $dir;
    }
}
