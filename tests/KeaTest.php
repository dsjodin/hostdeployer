<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Kea control client.
 *
 * Everything here runs without a DHCP server: the validation, which rejects a
 * bad change before any of it reaches the socket, and the behaviour when Kea
 * is not answering. The socket protocol itself is exercised separately against
 * a stand-in server -- it needs a live socket and a second process, which is
 * not something to require of a unit test run.
 *
 * The validation is the half worth pinning down anyway. It is what stops an
 * operator applying a gateway outside the subnet and making every host it
 * serves unable to reach the deployment server.
 */
final class KeaTest extends TestCase
{
    /** @return array<string, mixed> A settings array that should be accepted */
    private static function settings(array $overrides = []): array
    {
        return $overrides + [
            'start'     => '10.0.0.150',
            'end'       => '10.0.0.250',
            'netmask'   => '255.255.255.0',
            'gateway'   => '10.0.0.1',
            'dns'       => '10.0.0.53',
            'server_ip' => '10.0.0.2',
        ];
    }

    public function testReportsUnavailableWithNoSocket(): void
    {
        // The fixture root has no Kea in it.
        self::assertFalse(keaAvailable());
    }

    public function testStatusReportsTheReasonRatherThanThrowing(): void
    {
        // Called to render a settings page: a DHCP server that is down has to
        // show as down, not as a stack trace in the middle of the layout.
        $status = keaStatus();

        self::assertFalse($status['available']);
        self::assertNotSame('', $status['error']);
    }

    public function testCommandNamesTheMissingSocket(): void
    {
        $this->expectException(KeaException::class);
        $this->expectExceptionMessage('not there');

        keaCommand('version-get');
    }

    // -- validation, which happens before anything touches the socket -------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidSettingsProvider(): array
    {
        return [
            'malformed gateway'   => [['gateway' => 'nope'], 'Invalid gateway address'],
            'malformed start'     => [['start' => '999.1.1.1'], 'Invalid start address'],
            'malformed server'    => [['server_ip' => ''], 'Invalid server_ip address'],
            'non-contiguous mask' => [['netmask' => '255.0.255.0'], 'Invalid netmask'],
            'gateway off subnet'  => [['gateway' => '192.168.9.1'], 'Gateway is outside the DHCP subnet'],
            'server off subnet'   => [['server_ip' => '192.168.9.9'], 'Server ip is outside the DHCP subnet'],
            'end off subnet'      => [['end' => '192.168.9.250'], 'End is outside the DHCP subnet'],
            'pool reversed'       => [['start' => '10.0.0.250', 'end' => '10.0.0.150'], 'The pool starts above where it ends'],
            'malformed dns'       => [['dns' => '10.0.0.53,999.9.9.9'], 'Invalid DNS server: 999.9.9.9'],
            'no dns'              => [['dns' => ''], 'At least one DNS server is required'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidSettingsProvider')]
    public function testRejectsBadSettingsBeforeReachingKea(array $overrides, string $expected): void
    {
        $result = keaUpdateNetwork(self::settings($overrides));

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['error']);
    }

    /**
     * Settings that are themselves valid must get as far as the socket, and
     * fail there rather than in validation -- otherwise a validation bug looks
     * exactly like a DHCP server being down.
     */
    public function testValidSettingsFailAtTheSocketNotInValidation(): void
    {
        $result = keaUpdateNetwork(self::settings());

        self::assertFalse($result['success'], 'there is no Kea in the fixture');
        self::assertStringContainsString(
            'socket',
            $result['error'],
            'the failure should be about reaching Kea, not about the settings'
        );
    }

    public function testWhitespaceInTheDnsListIsTolerated(): void
    {
        $result = keaUpdateNetwork(self::settings(['dns' => ' 10.0.0.53 , 10.0.0.54 ']));

        // Rejected for want of a socket, not for the spaces.
        self::assertStringNotContainsString('Invalid DNS', $result['error']);
    }

    // -- netmask arithmetic --------------------------------------------------

    public function testNetmaskToPrefixLength(): void
    {
        self::assertSame(24, netmaskToPrefixLength('255.255.255.0'));
        self::assertSame(25, netmaskToPrefixLength('255.255.255.128'));
        self::assertSame(16, netmaskToPrefixLength('255.255.0.0'));
        self::assertSame(8, netmaskToPrefixLength('255.0.0.0'));
        self::assertSame(32, netmaskToPrefixLength('255.255.255.255'));
        self::assertSame(0, netmaskToPrefixLength('0.0.0.0'));
    }
}
