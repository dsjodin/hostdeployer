<?php
/**
 * Authentication, session and CSRF helpers for the ESXi Autodeploy admin.
 */

require_once __DIR__ . '/utils.php';

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

startAdminSession();

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
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Log the current user out and redirect to the login page.
 */
function logout() {
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
 * Check whether the current user holds a permission, per auth_config roles.
 *
 * @param string $permission Permission name (read, write, approve, scan, settings)
 * @return bool
 */
function hasPermission($permission) {
    $role = $_SESSION['role'] ?? null;
    if ($role === null) {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }

    $authConfig = loadAuthConfig();
    $permissions = $authConfig['roles'][$role]['permissions'] ?? [];

    return in_array($permission, $permissions, true);
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
