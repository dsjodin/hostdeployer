<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The approval gate.
 *
 * A host that reaches boot.cfg.php or boot.ipxe.php is anonymous -- the caller
 * is firmware with no credentials -- so the only thing standing between an
 * unapproved machine and an unattended reinstall is the deployment status this
 * function reads. Everything that is not explicitly approved has to be
 * refused, including values nobody planned for.
 */
final class BootGateTest extends TestCase
{
    public function testApprovedMayBoot(): void
    {
        self::assertSame(BOOT_GATE_ALLOW, bootGateGetDecision('approved'));
    }

    public function testDeployingMayBoot(): void
    {
        // A host reboots partway through an ESXi install and has to fetch the
        // same files again. Refusing here would break every install at the
        // point where it is least recoverable.
        self::assertSame(BOOT_GATE_ALLOW, bootGateGetDecision('deploying'));
    }

    public function testDeployedIsItsOwnAnswer(): void
    {
        // Not ALLOW: a finished host must not be reinstalled on every reboot.
        // Not REFUSE either, because boot.ipxe.php has something to say to it.
        self::assertSame(BOOT_GATE_DEPLOYED, bootGateGetDecision('deployed'));
    }

    public function testPendingIsRefused(): void
    {
        self::assertSame(BOOT_GATE_REFUSE, bootGateGetDecision('pending'));
    }

    /**
     * Everything the inventory can hold that is not one of the three known
     * statuses. A new status added to the UI without a thought for the boot
     * chain lands here, and lands closed.
     *
     * @return array<string, array{mixed}>
     */
    public static function refusedStatuses(): array
    {
        return [
            'pending'            => ['pending'],
            'discovered'         => ['discovered'],
            'failed'             => ['failed'],
            'unknown'            => ['unknown'],
            'empty string'       => [''],
            'missing (null)'     => [null],
            'leading space'      => [' approved'],
            'trailing newline'   => ["approved\n"],
            'different case'     => ['Approved'],
            'uppercase'          => ['APPROVED'],
            'substring'          => ['not-approved'],
            'approved-ish'       => ['approved-pending-review'],
            'integer'            => [1],
            'boolean true'       => [true],
            'array'              => [['approved']],
        ];
    }

    /**
     * @param mixed $status
     */
    #[DataProvider('refusedStatuses')]
    public function testAnythingElseIsRefused($status): void
    {
        self::assertSame(
            BOOT_GATE_REFUSE,
            bootGateGetDecision($status),
            'the gate must fail closed for ' . var_export($status, true)
        );
    }

    public function testTheThreeAnswersAreDistinct(): void
    {
        // The call sites branch on these, and two constants that compared
        // equal would make a refusal read as permission.
        self::assertCount(3, array_unique([BOOT_GATE_ALLOW, BOOT_GATE_DEPLOYED, BOOT_GATE_REFUSE]));
    }
}
