<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF token.
 *
 * Every state-changing action in the admin UI -- approve a host, delete one,
 * change the ESXi root password, upload a template -- is a POST guarded by
 * this one comparison. An operator's browser holding a valid session cookie is
 * all an attacker's page needs if the comparison can be made to pass without
 * the token, so the interesting cases here are the ones where it must fail:
 * nothing supplied, nothing in the session, and both.
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // The helpers start a session themselves; opening it here means each
        // test can put the session into a known state without the first call
        // under test being the one that creates it.
        startAdminSession();

        $_SESSION = [];
        $_POST    = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
    }

    // -----------------------------------------------------------------------
    // Issuing
    // -----------------------------------------------------------------------

    public function testATokenIsGeneratedWhenTheSessionHasNone(): void
    {
        self::assertArrayNotHasKey('csrf_token', $_SESSION);

        $token = csrfToken();

        self::assertNotSame('', $token);
        self::assertSame($token, $_SESSION['csrf_token']);
    }

    public function testTheTokenIsStableWithinASession(): void
    {
        // A page renders several forms. If each call minted a new token, only
        // the last form on the page would submit successfully.
        self::assertSame(csrfToken(), csrfToken());
    }

    public function testTheTokenIsLongAndUnguessable(): void
    {
        $token = csrfToken();

        // 32 random bytes, hex-encoded. Asserted rather than assumed because
        // a token shortened to something guessable is a token that no longer
        // guards anything, and nothing else in the suite would notice.
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        $first = csrfToken();

        $_SESSION = [];
        $second = csrfToken();

        self::assertNotSame($first, $second);
    }

    // -----------------------------------------------------------------------
    // Verifying
    // -----------------------------------------------------------------------

    public function testTheIssuedTokenVerifies(): void
    {
        $token = csrfToken();

        self::assertTrue(verifyCsrfToken(['csrf_token' => $token]));
    }

    public function testAWrongTokenIsRejected(): void
    {
        csrfToken();

        self::assertFalse(verifyCsrfToken(['csrf_token' => str_repeat('a', 64)]));
    }

    public function testATokenThatIsOnlyAPrefixIsRejected(): void
    {
        // hash_equals() compares lengths first. A comparison written with
        // strncmp() or a truncating cast would pass this and must not.
        $token = csrfToken();

        self::assertFalse(verifyCsrfToken(['csrf_token' => substr($token, 0, 32)]));
    }

    public function testATokenWithTrailingWhitespaceIsRejected(): void
    {
        $token = csrfToken();

        self::assertFalse(verifyCsrfToken(['csrf_token' => $token . ' ']));
    }

    /**
     * Request bodies are attacker-controlled and arrive in whatever shape the
     * attacker chose. None of these may pass, and none may raise: hash_equals()
     * throws a TypeError on a non-string, which would be a 500 on every POST
     * rather than a rejected one.
     *
     * @return array<string, array{mixed}>
     */
    public static function unusableSuppliedTokens(): array
    {
        return [
            'empty string' => [''],
            'array'        => [['a']],
            'null'         => [null],
            'integer zero' => [0],
            'boolean true' => [true],
            'float'        => [1.5],
        ];
    }

    /**
     * @param mixed $supplied
     */
    #[DataProvider('unusableSuppliedTokens')]
    public function testAnUnusableSuppliedTokenIsRejected($supplied): void
    {
        csrfToken();

        self::assertFalse(verifyCsrfToken(['csrf_token' => $supplied]));
    }

    public function testAMissingTokenIsRejected(): void
    {
        csrfToken();

        self::assertFalse(verifyCsrfToken([]));
        self::assertFalse(verifyCsrfToken(['some_other_field' => 'value']));
    }

    // -----------------------------------------------------------------------
    // The empty session
    // -----------------------------------------------------------------------

    public function testVerificationFailsWhenTheSessionHasNoToken(): void
    {
        // The case worth naming: with an empty session, a comparison written
        // as ($_SESSION['csrf_token'] ?? '') === $supplied would accept an
        // empty string and let an unauthenticated POST through. It has to
        // fail whatever is supplied.
        self::assertFalse(verifyCsrfToken(['csrf_token' => 'anything']));
        self::assertFalse(verifyCsrfToken(['csrf_token' => '']));
        self::assertFalse(verifyCsrfToken([]));
    }

    public function testAnEmptySessionTokenDoesNotMatchItself(): void
    {
        $_SESSION['csrf_token'] = '';

        self::assertFalse(verifyCsrfToken(['csrf_token' => '']));
    }

    // -----------------------------------------------------------------------
    // Defaults and rendering
    // -----------------------------------------------------------------------

    public function testVerificationFallsBackToPost(): void
    {
        // Most call sites pass nothing and rely on this.
        $token = csrfToken();

        $_POST = ['csrf_token' => $token];
        self::assertTrue(verifyCsrfToken());

        $_POST = ['csrf_token' => 'wrong'];
        self::assertFalse(verifyCsrfToken());

        $_POST = [];
        self::assertFalse(verifyCsrfToken());
    }

    public function testTheHiddenFieldCarriesTheToken(): void
    {
        $token = csrfToken();
        $field = csrfField();

        self::assertStringContainsString('name="csrf_token"', $field);
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString($token, $field);
    }

    public function testTheHiddenFieldEscapesTheToken(): void
    {
        // The token is hex today, so nothing needs escaping -- which is
        // exactly why the escaping would go unnoticed if it were dropped and
        // the token generator later changed.
        $_SESSION['csrf_token'] = 'a"b<c>d&e';

        $field = csrfField();

        self::assertStringNotContainsString('a"b<c>d&e', $field);
        self::assertStringContainsString('a&quot;b&lt;c&gt;d&amp;e', $field);
    }

    // -----------------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------------

    public function testLoggingInReplacesTheTokenIssuedBeforeLogin(): void
    {
        // Session fixation applies to the CSRF token too: a token an attacker
        // planted in the pre-login session must not still be valid once the
        // operator has authenticated.
        $before = csrfToken();

        establishSession(['username' => 'admin', 'role' => 'admin', 'name' => 'Admin']);

        self::assertNotSame($before, $_SESSION['csrf_token']);
        self::assertFalse(verifyCsrfToken(['csrf_token' => $before]));
        self::assertTrue(verifyCsrfToken(['csrf_token' => $_SESSION['csrf_token']]));
    }
}
