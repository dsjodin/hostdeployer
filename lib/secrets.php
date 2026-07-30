<?php
/**
 * Encryption of the secrets this appliance stores at rest.
 *
 * config/credentials.json holds the ESXi root password that every host is
 * installed with, and the iLO account that can power-cycle the estate. Until
 * now the only thing protecting them was the file mode. A backup, a snapshot,
 * a support bundle or a misplaced chmod exposed them in the clear.
 *
 * XChaCha20-Poly1305, not AES-256-GCM: libsodium's AES-GCM requires
 * sodium_crypto_aead_aes256gcm_is_available() -- AES-NI in hardware -- and
 * refuses to run without it, which would make the appliance's behaviour depend
 * on the CPU it was installed on. XChaCha20-Poly1305 is always available, and
 * its 24-byte nonce is large enough that random nonces never need a counter to
 * stay unique.
 *
 * Values are tagged "v1." so a later format can be told from this one, and so
 * a value that has not been encrypted yet is recognisable rather than being
 * fed to the decryptor as if it were ciphertext.
 */

require_once __DIR__ . '/utils.php';

if (!defined('AUTODEPLOY_SECRET_KEY_FILE')) {
    define('AUTODEPLOY_SECRET_KEY_FILE', AUTODEPLOY_CONFIG_DIR . '/secret.key');
}

/** Prefix marking a value as ciphertext in this format. */
const AUTODEPLOY_SECRET_PREFIX = 'v1.';

/**
 * Thrown when a secret cannot be encrypted or decrypted.
 *
 * A distinct type because the callers have to tell "this value is not
 * encrypted" apart from "the key is wrong" -- the first is a legacy record to
 * migrate, the second is a lost key, and treating them alike would silently
 * hand an installer the string "v1.AAAA..." as a root password.
 */
class SecretsException extends RuntimeException
{
}

/**
 * Load the encryption key, generating one on first use.
 *
 * @return string The raw 32-byte key
 * @throws SecretsException When the key cannot be read or created
 */
function secretsKey() {
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    if (is_file(AUTODEPLOY_SECRET_KEY_FILE)) {
        $hex = @file_get_contents(AUTODEPLOY_SECRET_KEY_FILE);
        if ($hex === false) {
            throw new SecretsException('Could not read ' . AUTODEPLOY_SECRET_KEY_FILE);
        }

        $raw = @sodium_hex2bin(trim($hex));
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            // Refuse rather than generate a replacement: a truncated key file
            // is recoverable from a backup, and quietly minting a new one
            // would make every stored password permanently undecryptable.
            throw new SecretsException(
                AUTODEPLOY_SECRET_KEY_FILE . ' is not a valid key. Restore it from backup; '
                . 'generating a new one makes every stored password unreadable.'
            );
        }

        $key = $raw;
        return $key;
    }

    $key = secretsCreateKey();
    return $key;
}

/**
 * Generate and persist a new key.
 *
 * Written to a temporary file and renamed into place. An interrupted write
 * that left a truncated key behind would render every stored password
 * undecryptable, which is not a failure worth risking to save a syscall.
 *
 * @return string The raw key
 * @throws SecretsException
 */
function secretsCreateKey() {
    $dir = dirname(AUTODEPLOY_SECRET_KEY_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
        throw new SecretsException("Could not create $dir");
    }

    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $hex = sodium_bin2hex($key);

    $tmp = @tempnam($dir, '.secret.key-');
    if ($tmp === false) {
        throw new SecretsException("Could not create a temporary file in $dir");
    }

    if (@file_put_contents($tmp, $hex) === false
        || !@chmod($tmp, 0o600)
        || !@rename($tmp, AUTODEPLOY_SECRET_KEY_FILE)) {
        @unlink($tmp);
        throw new SecretsException('Could not write ' . AUTODEPLOY_SECRET_KEY_FILE);
    }

    logMessage('Generated a new secrets key at ' . AUTODEPLOY_SECRET_KEY_FILE);

    return $key;
}

/**
 * @param mixed $value Value to test
 * @return bool True when the value is ciphertext in this format
 */
function secretIsEncrypted($value) {
    return is_string($value) && strncmp($value, AUTODEPLOY_SECRET_PREFIX, 3) === 0;
}

/**
 * Encrypt a value.
 *
 * Already-encrypted values are returned unchanged, so saving a document that
 * was only partly migrated does not double-encrypt half of it.
 *
 * @param string $plaintext Value to encrypt
 * @return string Tagged ciphertext
 * @throws SecretsException
 */
function secretEncrypt($plaintext) {
    $plaintext = (string)$plaintext;

    if ($plaintext === '' || secretIsEncrypted($plaintext)) {
        return $plaintext;
    }

    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $key = secretsKey();

    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $plaintext,
        '',      // no additional data
        $nonce,
        $key
    );

    return AUTODEPLOY_SECRET_PREFIX . base64_encode($nonce . $ciphertext);
}

/**
 * Decrypt a value produced by secretEncrypt().
 *
 * @param string $encrypted Tagged ciphertext
 * @return string The plaintext
 * @throws SecretsException When the value is not ciphertext, is truncated, or
 *                          fails authentication
 */
function secretDecrypt($encrypted) {
    if (!secretIsEncrypted($encrypted)) {
        throw new SecretsException('Value is not encrypted');
    }

    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false) {
        throw new SecretsException('Ciphertext is not valid base64');
    }

    $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    $minimum = $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    // Guard the split: a short value would otherwise reach the AEAD as an
    // empty nonce rather than being reported as the corrupt record it is.
    if (strlen($raw) < $minimum) {
        throw new SecretsException('Ciphertext is too short: ' . strlen($raw) . ' bytes');
    }

    $nonce = substr($raw, 0, $nonceLength);
    $ciphertext = substr($raw, $nonceLength);

    $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $ciphertext,
        '',
        $nonce,
        secretsKey()
    );

    if ($plaintext === false) {
        // Authentication failed: either the wrong key, or the value was
        // tampered with. The two are indistinguishable, which is the point.
        throw new SecretsException(
            'Could not decrypt: wrong key or altered ciphertext. If '
            . basename(AUTODEPLOY_SECRET_KEY_FILE) . ' was replaced, restore the original.'
        );
    }

    return $plaintext;
}

/**
 * Decrypt a value that may not be encrypted yet.
 *
 * An install that predates this change has plaintext in credentials.json.
 * Rejecting it would lock the operator out of their own appliance, so a
 * plaintext value is passed through and reported once. It is rewritten as
 * ciphertext the next time the file is saved -- or immediately, with
 * php lib/secrets.php --encrypt-credentials.
 *
 * @param mixed  $value Stored value
 * @param string $label What the value is, for the log line
 * @return string The plaintext
 */
function secretDecryptOrPassThrough($value, $label = 'a credential') {
    if (!is_string($value) || $value === '') {
        return '';
    }

    if (!secretIsEncrypted($value)) {
        logMessage(
            "Read $label in plaintext; it will be encrypted on the next write. "
            . 'Run php lib/secrets.php --encrypt-credentials to do it now.',
            'WARNING'
        );
        return $value;
    }

    return secretDecrypt($value);
}

// ---------------------------------------------------------------------------
// CLI helper: php lib/secrets.php --encrypt-credentials
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/store.php';

    if (($argv[1] ?? '') !== '--encrypt-credentials') {
        echo 'Usage: php secrets.php --encrypt-credentials' . PHP_EOL . PHP_EOL;
        echo '  Rewrites config/credentials.json with every secret encrypted,' . PHP_EOL;
        echo '  generating ' . AUTODEPLOY_SECRET_KEY_FILE . ' if it does not exist.' . PHP_EOL;
        exit(1);
    }

    try {
        $credentials = storeLoadCredentials();
        if (!is_array($credentials)) {
            fwrite(STDERR, 'Could not read ' . AUTODEPLOY_CREDENTIALS . PHP_EOL);
            exit(1);
        }

        // storeSaveCredentials() encrypts on the way out, so a load-and-save
        // round trip is the migration.
        if (!storeSaveCredentials($credentials)) {
            fwrite(STDERR, 'Could not write ' . AUTODEPLOY_CREDENTIALS . PHP_EOL);
            exit(1);
        }

        echo 'Encrypted the secrets in ' . AUTODEPLOY_CREDENTIALS . PHP_EOL;
        echo 'Key: ' . AUTODEPLOY_SECRET_KEY_FILE . PHP_EOL;
        echo PHP_EOL;
        echo 'Back this key up. Without it every stored password is unreadable.' . PHP_EOL;
        exit(0);
    } catch (SecretsException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
