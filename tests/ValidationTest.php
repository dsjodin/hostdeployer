<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validNetmaskProvider(): array
    {
        return [
            '/24' => ['255.255.255.0'],
            '/16' => ['255.255.0.0'],
            '/8'  => ['255.0.0.0'],
            '/25' => ['255.255.255.128'],
            '/32' => ['255.255.255.255'],
            '/0'  => ['0.0.0.0'],
        ];
    }

    #[DataProvider('validNetmaskProvider')]
    public function testContiguousNetmasksAreAccepted(string $mask): void
    {
        self::assertTrue(isValidNetmask($mask));
    }

    /**
     * A mask has to be a contiguous run of ones. A host handed 255.0.255.0
     * computes a nonsensical network and loses its default route -- the
     * failure is invisible until the installer cannot reach the server.
     *
     * @return array<string, array{string}>
     */
    public static function invalidNetmaskProvider(): array
    {
        return [
            'non-contiguous'  => ['255.0.255.0'],
            'gap in the run'  => ['255.255.0.255'],
            'inverted'        => ['0.0.0.255'],
            'out of range'    => ['999.999.999.999'],
            'not an address'  => ['not-a-mask'],
            'empty'           => [''],
        ];
    }

    #[DataProvider('invalidNetmaskProvider')]
    public function testNonContiguousAndMalformedNetmasksAreRejected(string $mask): void
    {
        self::assertFalse(isValidNetmask($mask));
    }

    public function testVlanIdBoundaries(): void
    {
        self::assertTrue(isValidVlanId(0), 'untagged is a legitimate value');
        self::assertTrue(isValidVlanId(1));
        self::assertTrue(isValidVlanId(4094), '4094 is the highest usable id');

        self::assertFalse(isValidVlanId(4095), '4095 is reserved');
        self::assertFalse(isValidVlanId(-1));
        self::assertFalse(isValidVlanId('abc'));
    }

    public function testHostnameValidation(): void
    {
        self::assertTrue(isValidHostname('esxi-01'));
        self::assertTrue(isValidHostname('esxi-01.example.com'));
        self::assertTrue(isValidHostname('a'));

        self::assertFalse(isValidHostname(''));
        self::assertFalse(isValidHostname('-leading-dash'));
        self::assertFalse(isValidHostname('trailing-dash-'));
        self::assertFalse(isValidHostname('has spaces'));
        self::assertFalse(isValidHostname('under_score'));
        self::assertFalse(isValidHostname(str_repeat('a', 254)));
    }

    /**
     * This function was referenced by the host editor but never defined, so
     * saving a host without an explicit hostname was a fatal error.
     */
    public function testExtractsShortHostnameFromFqdn(): void
    {
        self::assertSame('esxi-01', extractHostnameFromFQDN('esxi-01.example.com'));
        self::assertSame('esxi-01', extractHostnameFromFQDN('esxi-01'));
        self::assertSame('esxi-01', extractHostnameFromFQDN('  esxi-01.example.com  '));
        self::assertSame('', extractHostnameFromFQDN(''));
    }

    public function testIpv4Validation(): void
    {
        self::assertTrue(isValidIpv4('192.168.1.1'));
        self::assertFalse(isValidIpv4('256.1.1.1'));
        self::assertFalse(isValidIpv4('192.168.1'));
        self::assertFalse(isValidIpv4('::1'), 'ipv6 is not ipv4');
        self::assertTrue(isValidIp('::1'), 'but it is a valid ip');
    }
}
