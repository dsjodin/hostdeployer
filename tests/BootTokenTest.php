<?php
/**
 * The boot token is what stands between /ks.cfg and anyone who can name a MAC,
 * so the cases that matter are the ones where verification must say no.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootTokenTest extends TestCase
{
    private const MAC = '00:0c:29:91:cf:eb';

    protected function setUp(): void
    {
        db()->exec('DELETE FROM host_macs');
        db()->exec('DELETE FROM hosts');

        storeAddHost([
            'mac_address'       => self::MAC,
            'hostname'          => 'esxi-token',
            'deployment_status' => 'approved',
        ]);
    }

    public function testIssuedTokenVerifies(): void
    {
        $token = storeIssueBootToken(self::MAC);

        $this->assertNotSame('', $token);
        $this->assertTrue(storeVerifyBootToken(self::MAC, $token));
    }

    public function testTokenIsNotStoredInTheClear(): void
    {
        $token = storeIssueBootToken(self::MAC);

        $statement = db()->prepare('SELECT boot_token FROM hosts WHERE mac = ?');
        $statement->execute([self::MAC]);

        $this->assertNotSame($token, $statement->fetchColumn());
    }

    public function testWrongTokenIsRejected(): void
    {
        storeIssueBootToken(self::MAC);

        $this->assertFalse(storeVerifyBootToken(self::MAC, str_repeat('a', 64)));
    }

    public function testEmptyTokenIsRejected(): void
    {
        storeIssueBootToken(self::MAC);

        // The one that would matter most if it were wrong: a request with no
        // ?t= at all must not match a host, whatever the column holds.
        $this->assertFalse(storeVerifyBootToken(self::MAC, ''));
    }

    public function testTokenIsRejectedForAHostWithNoneIssued(): void
    {
        $this->assertFalse(storeVerifyBootToken(self::MAC, str_repeat('b', 64)));
    }

    public function testIssuingAgainInvalidatesThePrevious(): void
    {
        $first = storeIssueBootToken(self::MAC);
        $second = storeIssueBootToken(self::MAC);

        $this->assertNotSame($first, $second);
        $this->assertFalse(storeVerifyBootToken(self::MAC, $first));
        $this->assertTrue(storeVerifyBootToken(self::MAC, $second));
    }

    public function testClearedTokenNoLongerVerifies(): void
    {
        $token = storeIssueBootToken(self::MAC);
        storeClearBootToken(self::MAC);

        $this->assertFalse(storeVerifyBootToken(self::MAC, $token));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = storeIssueBootToken(self::MAC);

        db()->prepare('UPDATE hosts SET boot_token_expires = ? WHERE mac = ?')
            ->execute([time() - 1, self::MAC]);

        $this->assertFalse(storeVerifyBootToken(self::MAC, $token));
    }

    public function testTokenDoesNotVerifyForAnotherHost(): void
    {
        storeAddHost([
            'mac_address'       => 'aa:bb:cc:dd:ee:ff',
            'hostname'          => 'esxi-other',
            'deployment_status' => 'approved',
        ]);

        $token = storeIssueBootToken(self::MAC);

        $this->assertFalse(storeVerifyBootToken('aa:bb:cc:dd:ee:ff', $token));
    }

    public function testUnknownHostGetsNoToken(): void
    {
        $this->assertSame('', storeIssueBootToken('11:22:33:44:55:66'));
    }

    public function testASecondaryMacResolvesToTheSameToken(): void
    {
        storeUpdateHost(self::MAC, ['additional_macs' => ['00:0c:29:91:cf:ec']]);

        // A server reports whichever NIC booted, so the token has to follow the
        // host rather than the address that asked for it.
        $token = storeIssueBootToken('00:0c:29:91:cf:ec');

        $this->assertNotSame('', $token);
        $this->assertTrue(storeVerifyBootToken(self::MAC, $token));
    }

    public function testAnUpdateDoesNotDiscardTheToken(): void
    {
        $token = storeIssueBootToken(self::MAC);

        // storeUpsertHostRow() rebuilds the row from storeHostColumns(), which
        // deliberately does not list boot_token -- both so it survives an
        // ordinary edit and so it never reaches the API as part of a host.
        storeUpdateHost(self::MAC, ['hostname' => 'renamed']);

        $this->assertTrue(storeVerifyBootToken(self::MAC, $token));
    }

    public function testTheTokenIsNotPartOfAHostRecord(): void
    {
        storeIssueBootToken(self::MAC);

        // GET /v1/hosts returns these arrays verbatim.
        $host = storeFindHost(self::MAC);

        $this->assertIsArray($host);
        $this->assertArrayNotHasKey('boot_token', $host);
        $this->assertArrayNotHasKey('boot_token_expires', $host);
    }
}
