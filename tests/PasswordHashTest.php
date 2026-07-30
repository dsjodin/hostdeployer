<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The hash this produces goes straight into "rootpw --iscrypted" in the
 * kickstart. If it is not a valid SHA-512 crypt hash the host installs with a
 * root password nobody knows.
 */
final class PasswordHashTest extends TestCase
{
    public function testProducesASha512CryptHash(): void
    {
        $hash = generateEsxiPasswordHash('VMware1!');

        self::assertStringStartsWith('$6$', $hash);
        self::assertGreaterThan(20, strlen($hash));
    }

    public function testTheHashVerifiesAgainstThePassword(): void
    {
        $hash = generateEsxiPasswordHash('VMware1!');

        self::assertSame($hash, crypt('VMware1!', $hash));
        self::assertNotSame($hash, crypt('wrong-password', $hash));
    }

    /**
     * The salt used to come from str_shuffle(), which is not a CSPRNG and
     * repeats across processes seeded alike.
     */
    public function testTheSaltIsRandomPerCall(): void
    {
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $hashes[] = generateEsxiPasswordHash('same-password');
        }

        self::assertCount(10, array_unique($hashes), 'every call should use a fresh salt');
    }

    public function testTheSaltIsSixteenCharactersFromTheCryptAlphabet(): void
    {
        $hash = generateEsxiPasswordHash('x');
        $parts = explode('$', $hash);

        self::assertSame('6', $parts[1]);
        self::assertSame(16, strlen($parts[2]));
        self::assertMatchesRegularExpression('#^[./0-9A-Za-z]{16}$#', $parts[2]);
    }

    public function testRandomPasswordsAreTheRequestedLengthAndDistinct(): void
    {
        self::assertSame(16, strlen(generateRandomPassword()));
        self::assertSame(24, strlen(generateRandomPassword(24)));
        self::assertNotSame(generateRandomPassword(), generateRandomPassword());
    }
}
