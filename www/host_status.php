<?php
/**
 * Deployment status for the dashboard's progress poller.
 *
 * The REST API answers the same question, but it authenticates with a bearer
 * token and a browser has a session cookie. Rather than teach the API a second
 * authentication scheme -- or hand a token to JavaScript, where it would sit in
 * the page for anyone with the console open -- the dashboard gets its own
 * endpoint behind the session it already has.
 *
 * Read-only, and only over TLS: it lives under /admin/, which port 80
 * redirects.
 */

require_once __DIR__ . '/../lib/auth.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Not authenticate(): a redirect to the login page is HTML, and this is polled
// by fetch(). A 401 lets the page tell the operator their session expired.
$user = currentUser();
if ($user === null) {
    http_response_code(401);
    echo (string)json_encode(['error' => 'Session expired']);
    exit;
}

if (!hasPermission('read')) {
    http_response_code(403);
    echo (string)json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// Only the hosts still moving. A finished estate polls for nothing, and the
// response stays small however large the inventory grows.
$statuses = [];
foreach (storeLoadHosts() as $host) {
    if (($host['deployment_status'] ?? '') !== 'deploying') {
        continue;
    }

    $mac = formatMac($host['mac_address'] ?? '');
    if ($mac === '') {
        continue;
    }

    $statuses[$mac] = [
        'status'   => $host['deployment_status'],
        'progress' => max(0, min(100, (int)($host['progress'] ?? 0))),
        'text'     => (string)($host['progress_text'] ?? ''),
    ];
}

echo (string)json_encode(['hosts' => $statuses], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
