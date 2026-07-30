<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * These protect the ESXi root password every host is installed with. A
 * failure here is either "the appliance cannot read its own secrets" or "the
 * secrets were never actually encrypted".
 */
final class SecretsTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_SECRET_KEY_FILE);
    }

    public function testARoundTripReturnsThePlaintext(): void
    {
        $secret = 'VMware1!';

        self::assertSame($secret, secretDecrypt(secretEncrypt($secret)));
    }

    public function testCiphertextIsTaggedAndDoesNotContainThePlaintext(): void
    {
        $encrypted = secretEncrypt('VMware1!');

        self::assertStringStartsWith('v1.', $encrypted);
        self::assertStringNotContainsString('VMware1!', $encrypted);
        self::assertTrue(secretIsEncrypted($encrypted));
    }

    /**
     * A 24-byte random nonce per message is the whole reason XChaCha20 was
     * chosen; identical plaintexts must not produce identical ciphertexts.
     */
    public function testTheSameValueEncryptsDifferentlyEachTime(): void
    {
        $a = secretEncrypt('same');
        $b = secretEncrypt('same');

        self::assertNotSame($a, $b);
        self::assertSame('same', secretDecrypt($a));
        self::assertSame('same', secretDecrypt($b));
    }

    public function testHandlesUnicodeAndLongValues(): void
    {
        foreach (['pässwörd-åäö', str_repeat('x', 4096), '{"json":"like"}', "with\nnewline"] as $secret) {
            self::assertSame($secret, secretDecrypt(secretEncrypt($secret)));
        }
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        self::assertSame('', secretEncrypt(''));
    }

    public function testEncryptingTwiceDoesNotDoubleEncrypt(): void
    {
        $once = secretEncrypt('VMware1!');

        self::assertSame($once, secretEncrypt($once));
        self::assertSame('VMware1!', secretDecrypt($once));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $encrypted = secretEncrypt('VMware1!');

        // Flip a byte in the middle of the payload.
        $raw = base64_decode(substr($encrypted, 3), true);
        self::assertIsString($raw);
        $raw[30] = $raw[30] === "\x00" ? "\x01" : "\x00";

        $this->expectException(SecretsException::class);
        secretDecrypt('v1.' . base64_encode($raw));
    }

    public function testTruncatedCiphertextIsReportedNotFatal(): void
    {
        $this->expectException(SecretsException::class);
        $this->expectExceptionMessage('too short');

        secretDecrypt('v1.' . base64_encode('short'));
    }

    public function testNonBase64CiphertextIsRejected(): void
    {
        $this->expectException(SecretsException::class);
        secretDecrypt('v1.not!valid!base64!');
    }

    public function testDecryptingAPlainValueIsAnError(): void
    {
        $this->expectException(SecretsException::class);
        $this->expectExceptionMessage('not encrypted');

        secretDecrypt('VMware1!');
    }

    /**
     * An install that predates encryption has plaintext on disk. Refusing it
     * would lock the operator out of their own appliance.
     */
    public function testPlaintextIsPassedThroughForMigration(): void
    {
        self::assertSame('legacy-password', secretDecryptOrPassThrough('legacy-password'));
        self::assertSame('', secretDecryptOrPassThrough(''));
        self::assertSame('', secretDecryptOrPassThrough(null));
    }

    public function testPassThroughStillDecryptsRealCiphertext(): void
    {
        self::assertSame('VMware1!', secretDecryptOrPassThrough(secretEncrypt('VMware1!')));
    }

    // -- the key ------------------------------------------------------------

    public function testTheKeyIsCreatedOnFirstUseWithRestrictivePermissions(): void
    {
        @unlink(AUTODEPLOY_SECRET_KEY_FILE);
        clearstatcache();

        secretsCreateKey();

        self::assertFileExists(AUTODEPLOY_SECRET_KEY_FILE);
        self::assertSame('0600', substr(sprintf('%o', fileperms(AUTODEPLOY_SECRET_KEY_FILE)), -4));
    }

    public function testTheKeyIsThirtyTwoBytesHexEncoded(): void
    {
        @unlink(AUTODEPLOY_SECRET_KEY_FILE);
        $key = secretsCreateKey();

        self::assertSame(32, strlen($key));
        self::assertSame(64, strlen(trim((string)file_get_contents(AUTODEPLOY_SECRET_KEY_FILE))));
    }

    /**
     * Silently minting a replacement for a damaged key would make every stored
     * password permanently unreadable, and it would look like it worked.
     */
    public function testATruncatedKeyIsRefusedRatherThanReplaced(): void
    {
        file_put_contents(AUTODEPLOY_SECRET_KEY_FILE, 'deadbeef');

        // secretsKey() memoises, so this runs in a subprocess to get a cold
        // read of the damaged file.
        $result = $this->runInSubprocess('secretsKey();');

        self::assertNotSame(0, $result['code'], 'a truncated key must not be accepted');
        self::assertStringContainsString('not a valid key', $result['output']);
        self::assertSame(
            'deadbeef',
            file_get_contents(AUTODEPLOY_SECRET_KEY_FILE),
            'the damaged key must be left alone so it can be restored'
        );
    }

    public function testAValueEncryptedUnderAnotherKeyCannotBeRead(): void
    {
        $encrypted = secretEncrypt('VMware1!');

        @unlink(AUTODEPLOY_SECRET_KEY_FILE);
        $result = $this->runInSubprocess(
            'secretDecrypt(' . var_export($encrypted, true) . ');'
        );

        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('wrong key', $result['output']);
    }

    /**
     * Run a snippet against a cold copy of lib/secrets.php.
     *
     * @param string $snippet PHP to run after the include
     * @return array{code: int, output: string}
     */
    private function runInSubprocess(string $snippet): array
    {
        $code = sprintf(
            'putenv(%s); require %s; try { %s } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }',
            var_export('AUTODEPLOY_ROOT=' . AUTODEPLOY_ROOT, true),
            var_export(__DIR__ . '/../lib/secrets.php', true),
            $snippet
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);
        self::assertIsResource($process);

        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}
