<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The bearer token is the only thing between an unauthenticated request and
 * the endpoint that hands out ESXi root passwords.
 */
final class ApiAuthTest extends TestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        $this->token = apiGenerateToken();

        file_put_contents(AUTODEPLOY_AUTH_CONFIG, '<?php return ' . var_export([
            'users' => [
                'admin' => ['password_hash' => 'unused-here', 'role' => 'admin'],
            ],
            'roles' => [
                'admin'    => ['permissions' => ['read', 'write', 'approve', 'scan', 'settings']],
                'operator' => ['permissions' => ['read', 'approve', 'scan']],
                'viewer'   => ['permissions' => ['read']],
            ],
            'api_tokens' => [
                'automation' => ['token_hash' => apiTokenDigest($this->token), 'role' => 'operator'],
                'malformed'  => ['role' => 'admin'],
            ],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_AUTH_CONFIG);
    }

    public function testTheDigestIsStableAndNotTheTokenItself(): void
    {
        self::assertSame(apiTokenDigest('abc'), apiTokenDigest('abc'));
        self::assertNotSame('abc', apiTokenDigest('abc'));
        self::assertSame(64, strlen(apiTokenDigest('abc')));
    }

    public function testGeneratedTokensAreLongAndDistinct(): void
    {
        self::assertSame(64, strlen(apiGenerateToken()));
        self::assertNotSame(apiGenerateToken(), apiGenerateToken());
    }

    public function testAcceptsAConfiguredToken(): void
    {
        $identity = apiVerifyToken($this->token);

        self::assertNotNull($identity);
        self::assertSame('automation', $identity['name']);
        self::assertSame('operator', $identity['role']);
    }

    public function testRejectsAnUnknownToken(): void
    {
        self::assertNull(apiVerifyToken(apiGenerateToken()));
    }

    public function testRejectsAnEmptyToken(): void
    {
        self::assertNull(apiVerifyToken(''));
        self::assertNull(apiVerifyToken(null));
    }

    /**
     * A token digest is not a password hash; supplying the digest itself must
     * not authenticate.
     */
    public function testRejectsTheDigestAsIfItWereTheToken(): void
    {
        self::assertNull(apiVerifyToken(apiTokenDigest($this->token)));
    }

    public function testSkipsMalformedEntriesInsteadOfCrashing(): void
    {
        // The 'malformed' entry has no token_hash at all.
        self::assertNotNull(apiVerifyToken($this->token));
    }

    public function testParsesTheBearerHeader(): void
    {
        self::assertSame('abc123', apiBearerToken(['HTTP_AUTHORIZATION' => 'Bearer abc123']));
        self::assertSame('abc123', apiBearerToken(['HTTP_AUTHORIZATION' => 'bearer abc123']));
        self::assertSame('abc123', apiBearerToken(['HTTP_AUTHORIZATION' => 'Bearer   abc123  ']));
        self::assertSame('abc123', apiBearerToken(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc123']));
    }

    public function testIgnoresANonBearerHeader(): void
    {
        self::assertSame('', apiBearerToken(['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']));
        self::assertSame('', apiBearerToken(['HTTP_AUTHORIZATION' => '']));
        self::assertSame('', apiBearerToken([]));
    }

    // -- role permissions ---------------------------------------------------

    public function testAdminHoldsEveryPermission(): void
    {
        foreach (['read', 'write', 'approve', 'scan', 'settings', 'anything-at-all'] as $permission) {
            self::assertTrue(roleHasPermission('admin', $permission));
        }
    }

    public function testOperatorIsLimitedToItsList(): void
    {
        self::assertTrue(roleHasPermission('operator', 'read'));
        self::assertTrue(roleHasPermission('operator', 'approve'));
        self::assertFalse(roleHasPermission('operator', 'write'));

        // Reading credentials takes 'settings', which operator does not hold.
        self::assertFalse(roleHasPermission('operator', 'settings'));
    }

    public function testViewerCannotChangeAnything(): void
    {
        self::assertTrue(roleHasPermission('viewer', 'read'));
        foreach (['write', 'approve', 'scan', 'settings'] as $permission) {
            self::assertFalse(roleHasPermission('viewer', $permission));
        }
    }

    public function testAnUnknownOrAbsentRoleHoldsNothing(): void
    {
        self::assertFalse(roleHasPermission('does-not-exist', 'read'));
        self::assertFalse(roleHasPermission('', 'read'));
        self::assertFalse(roleHasPermission(null, 'read'));
    }

    // -- local helper token -------------------------------------------------

    public function testTheLocalTokenIsAbsentUntilItIsInstalled(): void
    {
        @unlink(AUTODEPLOY_API_LOCAL_TOKEN);

        self::assertSame('', apiLocalToken());
        self::assertSame('', apiLocalTokenEnv(), 'no token means no environment prefix');
    }

    public function testTheLocalTokenIsReadAndShellQuoted(): void
    {
        file_put_contents(AUTODEPLOY_API_LOCAL_TOKEN, "deadbeef\n");

        try {
            self::assertSame('deadbeef', apiLocalToken(), 'trailing newline is trimmed');
            self::assertSame("AUTODEPLOY_API_TOKEN='deadbeef' ", apiLocalTokenEnv());
        } finally {
            @unlink(AUTODEPLOY_API_LOCAL_TOKEN);
        }
    }
}
