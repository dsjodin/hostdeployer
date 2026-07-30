<?php
/**
 * Bearer-token authentication for the REST API.
 *
 * The admin UI authenticates with a session cookie and a CSRF token, which is
 * right for a browser and useless for a script. Automation gets a bearer token
 * instead: no session, no CSRF, no cookie jar.
 *
 * Tokens are stored as SHA-256 digests rather than bcrypt hashes. bcrypt earns
 * its cost against low-entropy human passwords; an API token is 32 bytes from
 * the CSPRNG, so there is nothing to brute force and a digest keyed lookup
 * stays constant time and O(1) in the number of tokens.
 *
 * Generate one with:
 *   php lib/api_auth.php automation
 */

require_once __DIR__ . '/auth.php';

if (!defined('AUTODEPLOY_API_TOKEN_BYTES')) {
    define('AUTODEPLOY_API_TOKEN_BYTES', 32);
}

/**
 * Hash a token for storage and comparison.
 *
 * @param string $token Raw token
 * @return string Hex encoded SHA-256 digest
 */
function apiTokenDigest($token) {
    return hash('sha256', (string)$token);
}

/**
 * Generate a new API token.
 *
 * @return string A 64 character hex token
 */
function apiGenerateToken() {
    return bin2hex(random_bytes(AUTODEPLOY_API_TOKEN_BYTES));
}

/**
 * Extract the bearer token from the request.
 *
 * php-fpm does not expose the Authorization header through $_SERVER on every
 * setup, so getallheaders() is consulted as well. The nginx site config passes
 * it explicitly, but a deployment behind a different front end should not fail
 * silently.
 *
 * @param array<string, mixed>|null $server Defaults to $_SERVER
 * @return string The token, or '' when the request carries none
 */
function apiBearerToken($server = null) {
    $server = $server ?? $_SERVER;

    $header = $server['HTTP_AUTHORIZATION']
        ?? $server['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
        return '';
    }

    return trim(substr($header, 7));
}

/**
 * Resolve a bearer token to the identity it belongs to.
 *
 * Every configured token is compared with hash_equals and the loop is not cut
 * short on the first match, so the time taken does not depend on where in the
 * list the token sits.
 *
 * @param string $token Raw bearer token
 * @return array{name: string, role: string}|null
 */
function apiVerifyToken($token) {
    if (!is_string($token) || $token === '') {
        return null;
    }

    $authConfig = loadAuthConfig();
    if ($authConfig === null) {
        return null;
    }

    $tokens = $authConfig['api_tokens'] ?? [];
    if (!is_array($tokens)) {
        return null;
    }

    $supplied = apiTokenDigest($token);
    $match = null;

    foreach ($tokens as $name => $entry) {
        if (!is_array($entry) || !isset($entry['token_hash']) || !is_string($entry['token_hash'])) {
            continue;
        }

        if (hash_equals($entry['token_hash'], $supplied)) {
            $match = [
                'name' => (string)$name,
                'role' => (string)($entry['role'] ?? 'operator'),
            ];
        }
    }

    return $match;
}

/**
 * Authenticate the current request.
 *
 * @return array{name: string, role: string}|null Null when the token is absent or unknown
 */
function apiAuthenticate() {
    $token = apiBearerToken();
    if ($token === '') {
        return null;
    }

    $identity = apiVerifyToken($token);

    if ($identity === null) {
        // The token itself is never logged; a rejected one may be a typo in a
        // script, and log files are read by more people than credentials are.
        auth_log('API request with an unrecognised bearer token from '
            . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'WARNING');
        return null;
    }

    return $identity;
}

// ---------------------------------------------------------------------------
// CLI helper: php lib/api_auth.php <name> [role]
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $name = $argv[1] ?? '';
    $role = $argv[2] ?? 'operator';

    if ($name === '') {
        echo 'Usage: php api_auth.php <token-name> [role]' . PHP_EOL;
        echo '       php api_auth.php --local' . PHP_EOL . PHP_EOL;
        echo '  --local  generate the token the admin UI uses when it shells' . PHP_EOL;
        echo '           out to the Python helpers, and write it to' . PHP_EOL;
        echo '           ' . AUTODEPLOY_API_LOCAL_TOKEN . PHP_EOL;
        exit(1);
    }

    $token = apiGenerateToken();

    if ($name === '--local') {
        $dir = dirname(AUTODEPLOY_API_LOCAL_TOKEN);
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            fwrite(STDERR, "Could not create $dir" . PHP_EOL);
            exit(1);
        }

        // Written the same way as any other secret here: a temporary file with
        // restrictive permissions, renamed into place, so a reader never sees
        // a half written token.
        $tmp = tempnam($dir, '.api_local_token-');
        if ($tmp === false
            || file_put_contents($tmp, $token) === false
            || !chmod($tmp, 0o640)
            || !rename($tmp, AUTODEPLOY_API_LOCAL_TOKEN)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            fwrite(STDERR, 'Could not write ' . AUTODEPLOY_API_LOCAL_TOKEN . PHP_EOL);
            exit(1);
        }

        echo 'Wrote ' . AUTODEPLOY_API_LOCAL_TOKEN . PHP_EOL;
        echo 'Make it readable by the web server:' . PHP_EOL;
        echo '  chown root:www-data ' . AUTODEPLOY_API_LOCAL_TOKEN . PHP_EOL . PHP_EOL;
        echo 'Add to config/auth_config.php under \'api_tokens\':' . PHP_EOL . PHP_EOL;
        echo "    'local-helpers' => [" . PHP_EOL;
        echo "        'token_hash' => '" . apiTokenDigest($token) . "'," . PHP_EOL;
        echo "        'role'       => 'admin'," . PHP_EOL;
        echo "    ]," . PHP_EOL;
        exit(0);
    }

    echo 'Token (store it now, it is not recoverable):' . PHP_EOL;
    echo '  ' . $token . PHP_EOL . PHP_EOL;
    echo 'Add to config/auth_config.php under \'api_tokens\':' . PHP_EOL . PHP_EOL;
    echo "    " . var_export($name, true) . " => [" . PHP_EOL;
    echo "        'token_hash' => '" . apiTokenDigest($token) . "'," . PHP_EOL;
    echo "        'role'       => " . var_export($role, true) . "," . PHP_EOL;
    echo "    ]," . PHP_EOL;
    exit(0);
}
