<?php
/**
 * The throttle used to live in $_SESSION, where dropping the cookie reset it.
 * These tests are about the property that replaced: state the client does not
 * hold, keyed so that one machine and one account are limited separately.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM login_attempts');
    }

    private function failAttempts(string $user, string $ip, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            authThrottleFail($user, $ip);
        }
    }

    public function testCleanSlateIsNotThrottled(): void
    {
        $this->assertSame(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }

    public function testBelowTheThresholdIsNotThrottled(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES - 1);

        $this->assertSame(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }

    public function testTheThresholdLocks(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);

        $this->assertGreaterThan(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }

    public function testStateDoesNotLiveWithTheClient(): void
    {
        // The whole point. Nothing about the caller is remembered anywhere the
        // caller controls, so discarding a cookie changes nothing.
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);
        $_SESSION = [];

        $this->assertGreaterThan(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }

    public function testLockingOneAddressLeavesAnotherAlone(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);

        // A different account from a different address shares neither key.
        $this->assertSame(0, authThrottleStatus('operator', '10.0.0.6')['wait']);
    }

    public function testAUsernameIsLockedAcrossAddresses(): void
    {
        // Spread over enough addresses that no single one reaches the
        // threshold: the address key never trips, the username key does.
        for ($i = 0; $i < AUTODEPLOY_LOGIN_MAX_FAILURES; $i++) {
            authThrottleFail('admin', '10.0.0.' . (100 + $i));
        }

        $status = authThrottleStatus('admin', '10.0.0.250');

        $this->assertGreaterThan(0, $status['wait']);
        $this->assertFalse(
            $status['by_address'],
            'a username lock must not be attributed to the address, or the '
                . 'message it produces confirms the account exists'
        );
    }

    public function testAnAddressLockIsSafeToDescribe(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);

        $this->assertTrue(authThrottleStatus('admin', '10.0.0.5')['by_address']);
    }

    public function testUsernameMatchingIsCaseInsensitive(): void
    {
        for ($i = 0; $i < AUTODEPLOY_LOGIN_MAX_FAILURES; $i++) {
            authThrottleFail('ADMIN', '10.0.0.' . (100 + $i));
        }

        $this->assertGreaterThan(0, authThrottleStatus('admin', '10.0.0.250')['wait']);
    }

    public function testSuccessClearsBothKeys(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);
        authThrottleReset('admin', '10.0.0.5');

        $this->assertSame(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }

    public function testTheLockIsCapped(): void
    {
        // An operator who mistypes their password should not be locked out for
        // the evening, so the backoff has a ceiling.
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES + 20);

        $this->assertLessThanOrEqual(
            AUTODEPLOY_LOGIN_MAX_LOCK,
            authThrottleStatus('admin', '10.0.0.5')['wait']
        );
    }

    public function testAnExpiredLockStopsApplying(): void
    {
        $this->failAttempts('admin', '10.0.0.5', AUTODEPLOY_LOGIN_MAX_FAILURES);

        db()->exec('UPDATE login_attempts SET locked_until = ' . (time() - 1));

        $this->assertSame(0, authThrottleStatus('admin', '10.0.0.5')['wait']);
    }
}
