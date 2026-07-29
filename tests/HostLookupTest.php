<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A server reports whichever NIC booted, so the lookup has to match secondary
 * MACs discovered by the iLO scan as well as the primary one. Getting this
 * wrong means a four-port server is treated as an unknown host every second
 * boot.
 */
final class HostLookupTest extends TestCase
{
    /** @var array{hosts: list<array<string, mixed>>} */
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'hosts' => [
                [
                    'mac_address'     => '00:0c:29:91:cf:eb',
                    'hostname'        => 'esxi-01',
                    'additional_macs' => ['00:0c:29:91:cf:ec', '00-0C-29-91-CF-ED'],
                ],
                [
                    'mac_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname'    => 'esxi-02',
                ],
            ],
        ];
    }

    public function testFindsAHostByItsPrimaryMac(): void
    {
        $host = findHostByMac('00:0c:29:91:cf:eb', $this->config);

        self::assertNotNull($host);
        self::assertSame('esxi-01', $host['hostname']);
    }

    public function testFindsAHostByAnAdditionalMac(): void
    {
        $host = findHostByMac('00:0c:29:91:cf:ec', $this->config);

        self::assertNotNull($host);
        self::assertSame('esxi-01', $host['hostname']);
    }

    public function testMatchingIsIndependentOfFormatting(): void
    {
        foreach (['00-0C-29-91-CF-EB', '000c2991cfeb', '00:0C:29:91:CF:EB'] as $variant) {
            $host = findHostByMac($variant, $this->config);

            self::assertNotNull($host, "should match $variant");
            self::assertSame('esxi-01', $host['hostname']);
        }
    }

    public function testAnAdditionalMacStoredInAnotherFormatStillMatches(): void
    {
        $host = findHostByMac('00:0c:29:91:cf:ed', $this->config);

        self::assertNotNull($host);
        self::assertSame('esxi-01', $host['hostname']);
    }

    public function testReturnsNullForAnUnknownMac(): void
    {
        self::assertNull(findHostByMac('11:22:33:44:55:66', $this->config));
    }

    /**
     * An invalid MAC must not fall through and match the first host with no
     * mac_address key -- that would hand an attacker someone else's kickstart.
     */
    public function testReturnsNullForAnInvalidMac(): void
    {
        self::assertNull(findHostByMac('not-a-mac', $this->config));
        self::assertNull(findHostByMac('', $this->config));
    }

    public function testReturnsNullForAMalformedConfiguration(): void
    {
        self::assertNull(findHostByMac('00:0c:29:91:cf:eb', []));
        self::assertNull(findHostByMac('00:0c:29:91:cf:eb', ['hosts' => 'not an array']));
    }

    public function testHostMatchesMacCoversPrimaryAndAdditional(): void
    {
        $host = $this->config['hosts'][0];

        self::assertTrue(hostMatchesMac($host, '00:0c:29:91:cf:eb'));
        self::assertTrue(hostMatchesMac($host, '00:0c:29:91:cf:ec'));
        self::assertTrue(hostMatchesMac($host, '00:0c:29:91:cf:ed'));
        self::assertFalse(hostMatchesMac($host, 'aa:bb:cc:dd:ee:ff'));
    }

    public function testHostMatchesMacToleratesAHostWithoutMacs(): void
    {
        self::assertFalse(hostMatchesMac(['hostname' => 'orphan'], '00:0c:29:91:cf:eb'));
    }
}
