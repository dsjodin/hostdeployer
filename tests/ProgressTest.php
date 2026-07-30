<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Progress is what tells an operator whether a twenty-minute install is
 * working or wedged, so the one property that matters is that it never lies:
 * it must not move backwards, and a client must not be able to set it.
 */
final class ProgressTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM hosts');
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'esxi-01']);
    }

    public function testANewHostStartsAtZero(): void
    {
        $host = storeFindHost('00:0c:29:91:cf:eb');

        self::assertSame(0, $host['progress']);
        self::assertSame('', $host['progress_text']);
    }

    public function testProgressIsRecorded(): void
    {
        self::assertTrue(storeSetProgress('00:0c:29:91:cf:eb', 50, 'installing'));

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame(50, $host['progress']);
        self::assertSame('installing', $host['progress_text']);
    }

    public function testProgressAdvancesThroughTheBootChain(): void
    {
        foreach ([[10, 'loading'], [50, 'installing'], [75, 'first boot'], [100, 'deployed']] as [$pct, $text]) {
            storeSetProgress('00:0c:29:91:cf:eb', $pct, $text);
            self::assertSame($pct, storeFindHost('00:0c:29:91:cf:eb')['progress']);
        }
    }

    /**
     * A host retrying a step mid-install, or rebooting into an installation
     * that already finished, must not appear to lose ground.
     */
    public function testProgressNeverMovesBackwards(): void
    {
        storeSetProgress('00:0c:29:91:cf:eb', 100, 'deployed');
        self::assertTrue(storeSetProgress('00:0c:29:91:cf:eb', 10, 'loading again'));

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame(100, $host['progress']);
        self::assertSame('deployed', $host['progress_text'], 'the text must not regress either');
    }

    public function testRepeatingTheSameStepIsAccepted(): void
    {
        storeSetProgress('00:0c:29:91:cf:eb', 50, 'installing');

        self::assertTrue(storeSetProgress('00:0c:29:91:cf:eb', 50, 'installing (retry)'));
        self::assertSame('installing (retry)', storeFindHost('00:0c:29:91:cf:eb')['progress_text']);
    }

    public function testValuesAreClampedToTheRange(): void
    {
        storeSetProgress('00:0c:29:91:cf:eb', 500, 'over');
        self::assertSame(100, storeFindHost('00:0c:29:91:cf:eb')['progress']);

        db()->exec('DELETE FROM hosts');
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb']);

        storeSetProgress('00:0c:29:91:cf:eb', -5, 'under');
        self::assertSame(0, storeFindHost('00:0c:29:91:cf:eb')['progress']);
    }

    public function testAnUnknownHostIsReportedNotCreated(): void
    {
        self::assertFalse(storeSetProgress('aa:bb:cc:dd:ee:ff', 50, 'installing'));
        self::assertCount(1, storeLoadHosts());
    }

    public function testProgressResolvesThroughASecondaryMac(): void
    {
        storeUpdateHost('00:0c:29:91:cf:eb', ['additional_macs' => ['00:0c:29:91:cf:ec']]);

        self::assertTrue(storeSetProgress('00:0c:29:91:cf:ec', 50, 'installing'));
        self::assertSame(50, storeFindHost('00:0c:29:91:cf:eb')['progress']);
    }

    /**
     * The percentages belong to the appliance, not the client. A host must not
     * be able to name its own step and jump the queue in the operator's view.
     */
    public function testTheStepTableIsClosed(): void
    {
        $steps = storeProgressSteps();

        self::assertSame(['firstboot', 'network', 'services'], array_keys($steps));

        foreach ($steps as $step => [$percentage, $text]) {
            self::assertIsInt($percentage, "$step has a numeric percentage");
            self::assertGreaterThan(50, $percentage, "$step comes after the kickstart");
            self::assertLessThan(100, $percentage, "$step is not completion; that is deployment_complete.php");
            self::assertNotSame('', $text, "$step has something to show the operator");
        }
    }
}
