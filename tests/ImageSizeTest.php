<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The recorded image size.
 *
 * imageList() used to call imageDirectorySize() per installed version on every
 * render -- a walk of ~500 files each, on the settings tab, on GET
 * /api/v1/images, and again on GET /api/v1/images/{version}, which iterates
 * imageList() to find one entry. The size is now written once at installation,
 * so what has to hold is that it is written, that it is read back, and that
 * there is a way to correct it when the disk changes underneath.
 */
final class ImageSizeTest extends TestCase
{
    private const VERSION = 'size-test';

    protected function setUp(): void
    {
        file_put_contents(AUTODEPLOY_GLOBAL_CONFIG, json_encode([
            'deployment' => ['esxi_versions' => [], 'default_version' => ''],
        ]));

        $dir = AUTODEPLOY_IMAGE_DIR . '/' . self::VERSION;
        @mkdir($dir . '/efi/boot', 0o750, true);
        file_put_contents($dir . '/boot.cfg', "kernel=b.b00\nmodules=a.gz\n");
        file_put_contents($dir . '/big.v00', str_repeat('x', 4096));
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_GLOBAL_CONFIG);
        @unlink(AUTODEPLOY_GLOBAL_CONFIG . '.lock');
        imageRemoveDirectory(self::VERSION);
    }

    private function imageEntry(): array
    {
        foreach (imageList() as $image) {
            if ($image['version'] === self::VERSION) {
                return $image;
            }
        }

        self::fail(self::VERSION . ' is not in imageList()');
    }

    public function testRegistrationRecordsTheSize(): void
    {
        self::assertTrue(imageRegister(self::VERSION, 'test image'));

        $config = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
        $recorded = $config['deployment']['esxi_versions'][self::VERSION]['size'] ?? null;

        // 4096 + the boot.cfg, whatever the filesystem reports for it.
        self::assertIsInt($recorded);
        self::assertGreaterThan(4096, $recorded);
    }

    public function testTheListReadsTheRecordedSize(): void
    {
        imageRegister(self::VERSION, 'test image');

        self::assertSame(
            imageDirectorySize(AUTODEPLOY_IMAGE_DIR . '/' . self::VERSION),
            $this->imageEntry()['size']
        );
    }

    public function testTheListStillChecksPresenceAndBootabilityLive(): void
    {
        imageRegister(self::VERSION, 'test image');

        $entry = $this->imageEntry();
        self::assertTrue($entry['present']);
        self::assertTrue($entry['bootable']);

        // Two stats and a small read; cheap enough to stay live, and a
        // directory removed by hand has to show as gone.
        imageRemoveDirectory(self::VERSION);

        $entry = $this->imageEntry();
        self::assertFalse($entry['present']);
        self::assertFalse($entry['bootable']);
        self::assertSame(0, $entry['size']);
    }

    public function testAVersionRegisteredWithoutASizeReportsZero(): void
    {
        // A configuration written before the size was recorded, or by hand.
        saveJsonConfig(AUTODEPLOY_GLOBAL_CONFIG, ['deployment' => ['esxi_versions' => [
            self::VERSION => ['path' => AUTODEPLOY_IMAGE_DIR . '/' . self::VERSION, 'description' => 'legacy'],
        ]]]);

        self::assertSame(0, $this->imageEntry()['size']);
    }

    public function testRefreshingRecountsAndRecords(): void
    {
        imageRegister(self::VERSION, 'test image');
        $before = $this->imageEntry()['size'];

        file_put_contents(AUTODEPLOY_IMAGE_DIR . '/' . self::VERSION . '/added.v00', str_repeat('y', 8192));

        // Still the old number: that is the trade the caching makes.
        self::assertSame($before, $this->imageEntry()['size']);

        $refreshed = imageRefreshSize(self::VERSION);

        self::assertGreaterThan($before, $refreshed);
        self::assertSame($refreshed, $this->imageEntry()['size']);
    }

    public function testRefreshingAnAbsentImageReportsZero(): void
    {
        imageRegister(self::VERSION, 'test image');
        imageRemoveDirectory(self::VERSION);

        self::assertSame(0, imageRefreshSize(self::VERSION));
    }

    public function testRefreshingAnUnknownVersionIsHarmless(): void
    {
        self::assertSame(0, imageRefreshSize('never-installed'));
        self::assertSame(0, imageRefreshSize('../etc'));
    }
}
