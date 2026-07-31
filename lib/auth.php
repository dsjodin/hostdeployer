<?php
/**
 * Authentication, session and CSRF helpers for the ESXi Autodeploy admin.
 */

require_once __DIR__ . '/store.php';

if (!defined('AUTODEPLOY_AUTH_CONFIG')) {
    define('AUTODEPLOY_AUTH_CONFIG', AUTODEPLOY_CONFIG_DIR . '/auth_config.php');
}
if (!defined('AUTODEPLOY_SESSION_TIMEOUT')) {
    define('AUTODEPLOY_SESSION_TIMEOUT', 1800); // 30 minutes of inactivity
}

/**
 * Start the session with hardened cookie parameters.
 *
 * Must be called before any output. Safe to call repeatedly.
 *
 * This is *not* invoked when the file is included. It used to be, which meant
 * including the authentication helpers emitted a Set-Cookie header as a side
 * effect -- so this file could not be pulled into a test, a CLI script or the
 * REST API without starting a browser session nobody asked for. Every page
 * that needs a session now says so.
 */
function startAdminSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('AUTODEPLOYSESSID');
    session_start();
}

/**
 * Append a message to the authentication log.
 *
 * @param string $message Message to log
 * @param string $level   Log level
 */
function auth_log($message, $level = 'INFO') {
    // The old code wrote to auth.php (not .log), which both hid the records
    // from the log viewer and dropped a writable .php file in the tree.
    logMessage($message, $level, AUTODEPLOY_LOG_DIR . '/auth.log');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/**
 * @return string The session CSRF token, generating one if needed
 */
function csrfToken() {
    startAdminSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * @return string A hidden input carrying the CSRF token
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token supplied with a state-changing request.
 *
 * @param array|null $postData Request data (defaults to $_POST)
 * @return bool True when the token matches
 */
function verifyCsrfToken($postData = null) {
    startAdminSession();

    $postData = $postData ?? $_POST;
    $supplied = $postData['csrf_token'] ?? '';

    if (!is_string($supplied) || $supplied === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $supplied);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

/**
 * Load the auth configuration.
 *
 * Returns null when the file is missing or malformed. There is deliberately
 * no built-in fallback account: the old code fell back to admin/password,
 * which silently opened the dashboard whenever the config failed to load.
 *
 * @return array|null
 */
function loadAuthConfig() {
    if (!is_file(AUTODEPLOY_AUTH_CONFIG)) {
        auth_log('Auth config not found at ' . AUTODEPLOY_AUTH_CONFIG, 'ERROR');
        return null;
    }

    $config = require AUTODEPLOY_AUTH_CONFIG;

    if (!is_array($config) || !isset($config['users']) || !is_array($config['users'])) {
        auth_log('Invalid auth configuration structure', 'ERROR');
        return null;
    }

    return $config;
}

/**
 * Verify a username/password pair against the auth configuration.
 *
 * @param string $username Supplied username
 * @param string $password Supplied password
 * @return array|null User data (username, role, name) or null on failure
 */
function verifyCredentials($username, $password) {
    $authConfig = loadAuthConfig();
    if ($authConfig === null) {
        return null;
    }

    $user = $authConfig['users'][$username] ?? null;

    // Always run a hash comparison so response time does not reveal whether
    // the account exists.
    $hash = $user['password_hash']
        ?? '$2y$12$usesomesillystringforsalttoavoidtiminglea.kQ8yQ0J6zVQm0Ck5xJZ.G';

    if (!password_verify($password, $hash) || $user === null) {
        return null;
    }

    return [
        'username' => $username,
        'role'     => $user['role'] ?? 'operator',
        'name'     => $user['name'] ?? $username,
    ];
}

/**
 * Establish an authenticated session for a user.
 *
 * @param array $userData Result of verifyCredentials()
 */
function establishSession(array $userData) {
    startAdminSession();

    // Defeat session fixation: the pre-login session id must not survive.
    session_regenerate_id(true);

    $_SESSION['authenticated'] = true;
    $_SESSION['username']      = $userData['username'];
    $_SESSION['role']          = $userData['role'];
    $_SESSION['name']          = $userData['name'] ?? $userData['username'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
}

/**
 * Require an authenticated session, redirecting to the login page otherwise.
 *
 * @param bool $logSuccess Whether to log successful authentications
 * @return array|false User data, or false (after redirecting) when not authenticated
 */
function authenticate($logSuccess = false) {
    startAdminSession();

    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        redirectToLogin();
        return false;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > AUTODEPLOY_SESSION_TIMEOUT)) {
        auth_log('Session expired for user ' . ($_SESSION['username'] ?? 'unknown'));
        destroySession();
        redirectToLogin();
        return false;
    }

    $_SESSION['last_activity'] = time();

    if ($logSuccess) {
        auth_log('User ' . $_SESSION['username'] . ' authenticated successfully');
    }

    return [
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'] ?? 'operator',
    ];
}

/**
 * Non-redirecting authentication check, for XHR endpoints.
 *
 * @return array|null User data or null
 */
function currentUser() {
    startAdminSession();

    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return null;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > AUTODEPLOY_SESSION_TIMEOUT)) {
        destroySession();
        return null;
    }

    $_SESSION['last_activity'] = time();

    return [
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'] ?? 'operator',
    ];
}

/**
 * Redirect to the login page.
 */
function redirectToLogin() {
    header('Location: login.php');
    exit;
}

/**
 * Clear and destroy the current session, including its cookie.
 */
function destroySession() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    session_destroy();
}

/**
 * Log the current user out and redirect to the login page.
 */
function logout() {
    startAdminSession();

    if (isset($_SESSION['username'])) {
        auth_log('User ' . $_SESSION['username'] . ' logged out');
    }

    destroySession();
    redirectToLogin();
}

/**
 * Check whether a user holds a role.
 *
 * @param array|false $userData     User data from authenticate()
 * @param string      $requiredRole Role to check for
 * @return bool
 */
function hasRole($userData, $requiredRole) {
    if (!is_array($userData) || !isset($userData['role'])) {
        return false;
    }

    if ($userData['role'] === 'admin') {
        return true;
    }

    return $userData['role'] === $requiredRole;
}

/**
 * Check whether a role holds a permission, per the auth_config role table.
 *
 * Split out from hasPermission() so the REST API can authorise a bearer
 * token's role without a session to read it from.
 *
 * @param string|null $role       Role name
 * @param string      $permission Permission name (read, write, approve, scan, settings)
 * @return bool
 */
function roleHasPermission($role, $permission) {
    if (!is_string($role) || $role === '') {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }

    $authConfig = loadAuthConfig();
    $permissions = $authConfig['roles'][$role]['permissions'] ?? [];

    return is_array($permissions) && in_array($permission, $permissions, true);
}

/**
 * Check whether the current session's user holds a permission.
 *
 * @param string $permission Permission name (read, write, approve, scan, settings)
 * @return bool
 */
function hasPermission($permission) {
    startAdminSession();

    return roleHasPermission($_SESSION['role'] ?? null, $permission);
}

// ---------------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------------
//
// The counter used to live in $_SESSION, so a client that did not send the
// cookie back got a fresh session -- and therefore a fresh counter -- on every
// attempt. curl without a cookie jar does that by default, which meant the
// throttle only ever slowed down a browser, and a browser is not what anyone
// brute forces a login with.
//
// Two keys per attempt. The address key is what stops one machine working
// through a wordlist; the username key is what stops a distributed attempt
// working through one account. They are counted separately because they fail
// for different reasons and only one of them is safe to talk about: saying "too
// many attempts from this address" reveals nothing, while saying "this account
// is locked" tells the asker the account exists.

if (!defined('AUTODEPLOY_LOGIN_MAX_FAILURES')) {
    define('AUTODEPLOY_LOGIN_MAX_FAILURES', 5);
}
if (!defined('AUTODEPLOY_LOGIN_MAX_LOCK')) {
    define('AUTODEPLOY_LOGIN_MAX_LOCK', 900); // 15 minutes
}

/**
 * The two rows an attempt touches.
 *
 * @param string $username Supplied username
 * @param string $ip       Client address
 * @return array{user: string, ip: string}
 */
function authThrottleSubjects($username, $ip) {
    // Lower-cased so "Admin" and "admin" share a counter, and length-bounded so
    // a long username cannot be used to fill the table.
    return [
        'user' => 'user:' . substr(strtolower(trim((string)$username)), 0, 64),
        'ip'   => 'ip:' . substr((string)$ip, 0, 64),
    ];
}

/**
 * How long the caller must wait, and whether it is safe to say why.
 *
 * Returns 0 when the attempt may proceed. A database that cannot be reached
 * fails open, with an error in the log: the inventory lives in the same file,
 * so an appliance that cannot read it is already not working, and locking the
 * operator out of the one page that could tell them so helps nobody.
 *
 * @param string $username Supplied username
 * @param string $ip       Client address
 * @return array{wait: int, by_address: bool}
 */
function authThrottleStatus($username, $ip) {
    $result = ['wait' => 0, 'by_address' => false];

    try {
        $subjects = authThrottleSubjects($username, $ip);
        $statement = db()->prepare(
            'SELECT subject, locked_until FROM login_attempts WHERE subject IN (?, ?)'
        );
        $statement->execute([$subjects['user'], $subjects['ip']]);

        $now = time();
        foreach ($statement->fetchAll() as $row) {
            $remaining = (int)$row['locked_until'] - $now;
            if ($remaining <= 0) {
                continue;
            }

            if ($remaining > $result['wait']) {
                $result['wait'] = $remaining;
            }
            if ($row['subject'] === $subjects['ip']) {
                $result['by_address'] = true;
            }
        }
    } catch (Throwable $e) {
        auth_log('Could not read the login throttle: ' . $e->getMessage(), 'ERROR');
    }

    return $result;
}

/**
 * Record a failed attempt against both keys.
 *
 * @param string $username Supplied username
 * @param string $ip       Client address
 */
function authThrottleFail($username, $ip) {
    try {
        $pdo = db();
        $now = time();

        $statement = $pdo->prepare(
            'INSERT INTO login_attempts (subject, failures, locked_until, updated)
             VALUES (:subject, 1, 0, :now)
             ON CONFLICT(subject) DO UPDATE SET failures = failures + 1, updated = :now'
        );
        $lock = $pdo->prepare(
            'UPDATE login_attempts SET locked_until = :until WHERE subject = :subject'
        );
        $read = $pdo->prepare('SELECT failures FROM login_attempts WHERE subject = ?');

        foreach (authThrottleSubjects($username, $ip) as $subject) {
            $statement->execute(['subject' => $subject, 'now' => $now]);

            $read->execute([$subject]);
            $failures = (int)$read->fetchColumn();

            if ($failures >= AUTODEPLOY_LOGIN_MAX_FAILURES) {
                // Doubling from 30 seconds, capped. Long enough that a wordlist
                // is not worth running, short enough that an operator who
                // mistyped their password is not locked out for the evening.
                $delay = min(
                    AUTODEPLOY_LOGIN_MAX_LOCK,
                    30 * (2 ** min(10, $failures - AUTODEPLOY_LOGIN_MAX_FAILURES))
                );
                $lock->execute(['until' => $now + (int)$delay, 'subject' => $subject]);
            }
        }

        // Rows nobody has touched for a day are of no further use, and this is
        // the only place that writes often enough to notice.
        $pdo->prepare('DELETE FROM login_attempts WHERE updated < ? AND locked_until < ?')
            ->execute([$now - 86400, $now]);
    } catch (Throwable $e) {
        auth_log('Could not record a failed login: ' . $e->getMessage(), 'ERROR');
    }
}

/**
 * Clear both keys after a successful authentication.
 *
 * @param string $username Supplied username
 * @param string $ip       Client address
 */
function authThrottleReset($username, $ip) {
    try {
        $subjects = authThrottleSubjects($username, $ip);
        db()->prepare('DELETE FROM login_attempts WHERE subject IN (?, ?)')
            ->execute([$subjects['user'], $subjects['ip']]);
    } catch (Throwable $e) {
        auth_log('Could not clear the login throttle: ' . $e->getMessage(), 'ERROR');
    }
}

/**
 * Generate a password hash for auth_config.php.
 *
 * @param string $password Plain text password
 * @return string Hash
 */
function generatePasswordHash($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// CLI helper: php lib/auth.php <password>
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    if (isset($argv[1])) {
        echo generatePasswordHash($argv[1]) . PHP_EOL;
    } else {
        echo 'Usage: php auth.php <password_to_hash>' . PHP_EOL;
    }
}
