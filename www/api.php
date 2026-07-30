<?php
/**
 * REST API front controller.
 *
 * Everything the admin UI can do, automation can do too. That is the point:
 * a provisioning appliance that can only be driven by a human at a browser is
 * not much use in a data centre.
 *
 * The handlers deliberately delegate to the same process*Action() functions
 * the HTML forms post into. Reimplementing the validation here would give two
 * definitions of a valid host that drift apart -- and the form path is the one
 * with the network validation already written.
 *
 * Routed by nginx from /api/ on the TLS listener only. It is never reachable
 * over plain HTTP: port 80 belongs to the boot chain, whose clients are
 * firmware with no credentials, and an API token must not cross it.
 */

require_once __DIR__ . '/../lib/api_auth.php';
require_once __DIR__ . '/host_functions.php';
require_once __DIR__ . '/hardware_functions.php';
require_once __DIR__ . '/../lib/images.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

const API_LOG = AUTODEPLOY_LOG_DIR . '/api.log';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * @param string $message Message
 * @param string $level   Level
 */
function apiLog($message, $level = 'INFO') {
    logMessage($message, $level, API_LOG);
}

/**
 * Emit a JSON response and stop.
 *
 * @param mixed $payload Response body
 * @param int   $status  HTTP status code
 */
function apiRespond($payload, $status = 200) {
    http_response_code($status);
    echo (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emit an error response and stop.
 *
 * @param string $message Human readable error
 * @param int    $status  HTTP status code
 */
function apiError($message, $status = 400) {
    apiRespond(['error' => $message], $status);
}

/**
 * Decode the JSON request body.
 *
 * @return array<string, mixed>
 */
function apiBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        apiError('Request body must be a JSON object', 400);
    }

    return $decoded;
}

/**
 * Translate a process*Action() result into an HTTP response.
 *
 * Those helpers report failure as a message rather than an exception, and
 * "not found" has to come back as 404 rather than 400 so a client can tell a
 * missing host from a bad request.
 *
 * @param array{message?: string, error?: string} $result Handler result
 * @param mixed                                   $body   Payload to return on success
 */
function apiRespondToActionResult(array $result, $body = null) {
    $error = $result['error'] ?? '';

    if ($error !== '') {
        $status = stripos($error, 'not found') !== false ? 404 : 400;
        apiError($error, $status);
    }

    apiRespond($body ?? ['message' => $result['message'] ?? 'ok']);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

$identity = apiAuthenticate();
if ($identity === null) {
    header('WWW-Authenticate: Bearer realm="hostdeployer"');
    apiError('Authentication required', 401);
}

/**
 * Require a permission for the authenticated token, or stop with 403.
 *
 * @param string $permission Permission name
 */
function apiRequire($permission) {
    global $identity;

    if (!roleHasPermission($identity['role'], $permission)) {
        apiLog("Token '{$identity['name']}' (role {$identity['role']}) denied '$permission'", 'WARNING');
        apiError("This token does not hold the '$permission' permission", 403);
    }
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// nginx passes the path after /api/ in PATH_INFO; fall back to parsing the
// request URI so the endpoint still works behind a different front end.
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
}
// Tolerate a front end that passes the prefix along with the route.
$path = preg_replace('#^/api(?=/|$)#', '', $path) ?? '';
$segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

// Everything lives under /v1 so a future shape change has somewhere to go.
if (($segments[0] ?? '') !== 'v1') {
    apiError('Unknown API version; use /api/v1/', 404);
}
array_shift($segments);

$resource = $segments[0] ?? '';

try {
    switch ($resource) {
        case 'hosts':
            apiHandleHosts($method, array_slice($segments, 1));
            break;

        case 'credentials':
            apiHandleCredentials($method, array_slice($segments, 1));
            break;

        case 'versions':
            apiRequire('read');
            if ($method !== 'GET') {
                apiError('Method not allowed', 405);
            }
            $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG) ?? [];
            apiRespond([
                'default'  => $globalConfig['deployment']['default_version'] ?? '',
                'versions' => array_keys($globalConfig['deployment']['esxi_versions'] ?? []),
            ]);
            break;

        case 'images':
            apiHandleImages($method, array_slice($segments, 1));
            break;

        case 'scan':
            apiRequire('scan');
            if ($method !== 'POST') {
                apiError('Method not allowed', 405);
            }
            apiLog("Token '{$identity['name']}' started an iLO scan");
            $scan = runIloScanner();
            apiRespond([
                'success' => (bool)$scan['success'],
                'output'  => $scan['output'],
            ], $scan['success'] ? 200 : 502);
            break;

        default:
            apiError('Unknown endpoint', 404);
    }
} catch (Throwable $e) {
    apiLog('Unhandled exception: ' . $e->getMessage(), 'ERROR');
    apiError('Internal server error', 500);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * /v1/hosts and everything under it.
 *
 * @param string   $method HTTP method
 * @param string[] $rest   Path segments after "hosts"
 */
function apiHandleHosts($method, array $rest) {
    global $identity;

    // Collection: /v1/hosts
    if ($rest === []) {
        if ($method === 'GET') {
            apiRequire('read');
            apiRespond(['hosts' => storeLoadHosts()]);
        }

        if ($method === 'POST') {
            apiRequire('write');
            $body = apiBody();
            $result = processAddHostAction($body);
            if (($result['error'] ?? '') === '') {
                apiLog("Token '{$identity['name']}' created host " . formatMac($body['mac'] ?? ''));
            }
            apiRespondToActionResult($result, null);
        }

        apiError('Method not allowed', 405);
    }

    // Discovery results: /v1/hosts/discovered
    //
    // Not a MAC, so it is matched before the path segment is parsed as one.
    if ($rest === ['discovered']) {
        apiRequire('write');
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }

        $body = apiBody();
        $discovered = $body['hosts'] ?? null;
        if (!is_array($discovered)) {
            apiError('Expected a "hosts" array of scan results', 400);
        }

        $merged = storeMergeDiscoveredHosts($discovered);
        if (!$merged['ok']) {
            apiError('Failed to write the host inventory', 500);
        }

        apiLog("Token '{$identity['name']}' merged a hardware scan: "
            . "{$merged['added']} added, {$merged['updated']} updated");

        apiRespond(['added' => $merged['added'], 'updated' => $merged['updated']]);
    }

    // The MAC is a path segment, so it is normalised before it is used to
    // address anything -- "00-0C-29" and "00:0c:29" are the same host.
    $mac = formatMac($rest[0]);
    if ($mac === '') {
        apiError('Invalid MAC address in path', 400);
    }

    $action = $rest[1] ?? '';

    if ($action === '') {
        switch ($method) {
            case 'GET':
                apiRequire('read');
                $host = storeFindHost($mac);
                if ($host === null) {
                    apiError('Host not found', 404);
                }
                apiRespond($host);
                // no break: apiRespond exits

            case 'PATCH':
                apiRequire('write');
                // processAddHostAction() merges into an existing record, so it
                // serves as the update path too. The MAC comes from the URL,
                // not the body, so a request cannot rename the host it edits.
                $result = processAddHostAction(apiBody() + ['mac' => $mac]);
                apiRespondToActionResult($result);
                // no break

            case 'DELETE':
                apiRequire('write');
                $result = processDeleteHostAction(['mac' => $mac]);
                if (($result['error'] ?? '') === '') {
                    apiLog("Token '{$identity['name']}' deleted host $mac");
                }
                apiRespondToActionResult($result);
                // no break

            default:
                apiError('Method not allowed', 405);
        }
    }

    if ($action === 'status') {
        apiRequire('read');
        if ($method !== 'GET') {
            apiError('Method not allowed', 405);
        }

        $host = storeFindHost($mac);
        if ($host === null) {
            apiError('Host not found', 404);
        }

        apiRespond([
            'mac'                => $mac,
            'hostname'           => $host['hostname'] ?? '',
            'deployment_status'  => $host['deployment_status'] ?? 'unknown',
            'secure_boot_status' => $host['secure_boot_status'] ?? 'unknown',
            'last_seen'          => $host['last_seen'] ?? null,
            'deployment_started' => $host['deployment_started'] ?? null,
            'deployment_time'    => $host['deployment_time'] ?? null,
        ]);
    }

    // A narrow route for the one field the secure boot helper owns. Sending it
    // through PATCH /v1/hosts/{mac} would mean passing the host editor's
    // validation -- FQDN, management address -- to record a BIOS setting on a
    // host that has not been configured yet.
    if ($action === 'secure-boot') {
        apiRequire('write');
        if ($method !== 'PATCH' && $method !== 'PUT') {
            apiError('Method not allowed', 405);
        }

        $status = (string)(apiBody()['status'] ?? '');
        if (!in_array($status, ['enabled', 'disabled', 'unknown'], true)) {
            apiError('status must be one of: enabled, disabled, unknown', 400);
        }

        if (storeFindHost($mac) === null) {
            apiError('Host not found', 404);
        }

        if (!storeUpdateHost($mac, ['secure_boot_status' => $status])) {
            apiError('Failed to update the host', 500);
        }

        apiLog("Token '{$identity['name']}' set secure boot to $status for $mac");
        apiRespond(['mac' => $mac, 'secure_boot_status' => $status]);
    }

    if ($method !== 'POST') {
        apiError('Method not allowed', 405);
    }

    switch ($action) {
        case 'approve':
            apiRequire('approve');
            // Approval needs the network details, which the body carries; the
            // existing record supplies whatever the caller leaves out.
            $existing = storeFindHost($mac);
            if ($existing === null) {
                apiError('Host not found', 404);
            }
            $result = processApproveHostAction(apiApprovalPayload($mac, $existing, apiBody()));
            if (($result['error'] ?? '') === '') {
                apiLog("Token '{$identity['name']}' approved host $mac");
            }
            apiRespondToActionResult($result);
            break;

        case 'reinstall':
            apiRequire('approve');
            $result = processReinstallHostAction(['mac' => $mac]);
            if (($result['error'] ?? '') === '') {
                apiLog("Token '{$identity['name']}' queued host $mac for reinstallation");
            }
            apiRespondToActionResult($result);
            break;

        default:
            apiError('Unknown endpoint', 404);
    }
}

/**
 * Build the payload processApproveHostAction() expects.
 *
 * The form posts every field on every submit; an API caller approving an
 * already-configured host should not have to restate its addressing. Anything
 * the body omits falls back to what is already on the record.
 *
 * @param string               $mac      Normalised MAC
 * @param array<string, mixed> $existing Current host record
 * @param array<string, mixed> $body     Request body
 * @return array<string, mixed>
 */
function apiApprovalPayload($mac, array $existing, array $body) {
    $payload = [
        'mac'                => $mac,
        'hostname'           => $body['hostname'] ?? ($existing['hostname'] ?? ''),
        'fqdn'               => $body['fqdn'] ?? ($existing['fqdn'] ?? ''),
        'management_ip'      => $body['management_ip'] ?? ($existing['management_ip'] ?? ''),
        'management_netmask' => $body['management_netmask'] ?? ($existing['management_netmask'] ?? '255.255.255.0'),
        'management_gateway' => $body['management_gateway'] ?? ($existing['management_gateway'] ?? ''),
        'deployment_type'    => $body['deployment_type'] ?? ($existing['deployment_type'] ?? 'standard'),
        'vlan_mgmt'          => $body['vlan_mgmt'] ?? ($existing['vlans']['management'] ?? 0),
        'vmotion_ip'         => $body['vmotion_ip'] ?? ($existing['vmotion_ip'] ?? ''),
        'vmotion_netmask'    => $body['vmotion_netmask'] ?? ($existing['vmotion_netmask'] ?? '255.255.255.0'),
        'vlan_vmotion'       => $body['vlan_vmotion'] ?? ($existing['vlans']['vmotion'] ?? 0),
    ];

    // Credential overrides are only touched when the caller says so, matching
    // the form's checkbox semantics: absent means "leave what is stored".
    foreach (['use_custom_ilo', 'ilo_username', 'ilo_password', 'use_custom_esxi', 'esxi_password'] as $key) {
        if (array_key_exists($key, $body)) {
            $payload[$key] = $body[$key];
        }
    }

    return $payload;
}

/**
 * /v1/credentials/{type}
 *
 * This is what lets the Python helpers stop reading credentials.json off the
 * disk. Once they come through here, the file's format is an implementation
 * detail of lib/store.php -- which is what makes encrypting it at rest a
 * change in one place.
 *
 * @param string   $method HTTP method
 * @param string[] $rest   Path segments after "credentials"
 */
function apiHandleCredentials($method, array $rest) {
    global $identity;

    $type = $rest[0] ?? '';
    if (!in_array($type, ['ilo', 'esxi'], true)) {
        apiError('Unknown credential type; expected ilo or esxi', 404);
    }

    // Reading credentials is not an ordinary read: it hands out the passwords
    // this appliance exists to protect. It takes the settings permission,
    // which by default only admin holds.
    apiRequire('settings');

    if ($method === 'GET') {
        $mac = isset($_GET['mac']) ? formatMac($_GET['mac']) : null;
        if (isset($_GET['mac']) && $mac === '') {
            apiError('Invalid MAC address', 400);
        }

        $credentials = storeLoadCredentials($type, $mac);
        if ($credentials === null) {
            apiError('No credentials configured for ' . $type, 404);
        }

        apiLog("Token '{$identity['name']}' read $type credentials"
            . ($mac !== null ? " for $mac" : ''));

        apiRespond($credentials);
    }

    if ($method === 'PUT') {
        $body = apiBody();

        $all = storeLoadCredentials();
        if (!is_array($all)) {
            $all = [];
        }
        if (!isset($all[$type]) || !is_array($all[$type])) {
            $all[$type] = [];
        }

        // Per-host overrides are managed through the host endpoints; a write
        // here replaces the defaults only, so a bulk PUT cannot silently drop
        // every override.
        $hosts = $all[$type]['hosts'] ?? [];
        unset($body['hosts']);
        $all[$type] = array_merge($all[$type], $body);
        $all[$type]['hosts'] = $hosts;

        if (!storeSaveCredentials($all)) {
            apiError('Failed to write the credentials file', 500);
        }

        apiLog("Token '{$identity['name']}' updated the $type credentials");
        apiRespond(['message' => ucfirst($type) . ' credentials updated']);
    }

    apiError('Method not allowed', 405);
}

/**
 * /v1/images -- the installed ESXi media.
 *
 * Uploading an ISO here replaces four manual steps on the deployment server:
 * mount, copy, unmount, edit global_config.json. None of those were checked,
 * and a mistake in any of them surfaced as a host that boots the installer and
 * then cannot find its modules.
 *
 * @param string   $method HTTP method
 * @param string[] $rest   Path segments after "images"
 */
function apiHandleImages($method, array $rest) {
    global $identity;

    if ($rest === []) {
        if ($method === 'GET') {
            apiRequire('read');
            apiRespond([
                'images'    => imageList(),
                // Named so a failed upload can be diagnosed without shell
                // access: "no extractor" is a different problem to "bad ISO".
                'extractor' => imageAvailableExtractor(),
            ]);
        }

        if ($method === 'POST') {
            apiRequire('settings');

            $upload = $_FILES['image'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                apiError('Expected a multipart upload in the "image" field: ' . apiUploadError($upload), 400);
            }

            $version = (string)($_POST['version'] ?? '');
            if (!imageIsValidVersionName($version)) {
                apiError('The version name may only contain letters, digits, dot, dash and underscore', 400);
            }

            $result = imageInstall(
                $upload['tmp_name'],
                $version,
                (string)($_POST['description'] ?? ''),
                (string)($_POST['sha256'] ?? '')
            );

            // The upload is a temporary file either way; PHP removes it when
            // the request ends, but not before this handler could have copied
            // several gigabytes of it into the image directory on failure.
            if (!$result['success']) {
                apiError($result['error'], 400);
            }

            apiLog("Token '{$identity['name']}' installed ESXi version $version");
            apiRespond(['message' => $result['message'], 'version' => $version]);
        }

        apiError('Method not allowed', 405);
    }

    $version = (string)$rest[0];
    if (!imageIsValidVersionName($version)) {
        apiError('Invalid version name', 400);
    }

    if ($method === 'DELETE') {
        apiRequire('settings');

        $dir = imageDirectory($version);
        if ($dir === null || !is_dir($dir)) {
            apiError('That ESXi version is not installed', 404);
        }

        // A host still pointing at this version would fail to boot, so say so
        // rather than discovering it at the boot prompt.
        $inUse = [];
        foreach (storeLoadHosts() as $host) {
            if (($host['esxi_version'] ?? '') === $version) {
                $inUse[] = formatMac($host['mac_address'] ?? '');
            }
        }

        if ($inUse !== [] && empty($_GET['force'])) {
            apiError(
                'Still assigned to ' . count($inUse) . ' host(s): ' . implode(', ', array_slice($inUse, 0, 5))
                    . '. Reassign them, or repeat with ?force=1.',
                409
            );
        }

        if (!imageRemoveDirectory($version)) {
            apiError('Could not remove the image directory', 500);
        }

        imageUnregister($version);

        apiLog("Token '{$identity['name']}' deleted ESXi version $version");
        apiRespond(['message' => "ESXi version '$version' removed"]);
    }

    if ($method === 'GET') {
        apiRequire('read');

        foreach (imageList() as $image) {
            if ($image['version'] === $version) {
                apiRespond($image);
            }
        }

        apiError('That ESXi version is not installed', 404);
    }

    apiError('Method not allowed', 405);
}

/**
 * Turn a PHP upload error code into something an operator can act on.
 *
 * @param array<string, mixed>|null $upload Entry from $_FILES
 * @return string
 */
function apiUploadError($upload) {
    $code = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            // The likeliest failure by far: an ESXi ISO is several gigabytes
            // and the stock limits are measured in megabytes.
            return 'the file exceeds the upload limit; raise upload_max_filesize and post_max_size in php.ini '
                . 'and client_max_body_size in nginx';
        case UPLOAD_ERR_PARTIAL:
            return 'the upload was interrupted';
        case UPLOAD_ERR_NO_FILE:
            return 'no file was sent';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'PHP has no temporary directory to write to';
        case UPLOAD_ERR_CANT_WRITE:
            return 'PHP could not write the upload to disk';
        default:
            return 'upload error code ' . $code;
    }
}
