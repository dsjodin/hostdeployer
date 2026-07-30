<?php
/**
 * ESXi Auto-deployment Admin Dashboard
 *
 * Central control interface. Tab content and per-tab action handlers live in
 * separate files that are included from here.
 */

// Guard used by the tab files to refuse direct HTTP access.
define('ADMIN_DASHBOARD', true);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/images.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

/**
 * @param string $message Message
 * @param string $level   Level
 */
function dashboard_log($message, $level = 'INFO') {
    logMessage($message, $level, AUTODEPLOY_LOG_DIR . '/admin_dashboard.log');
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

// Log a successful authentication only when the visitor arrived from outside
// the dashboard, so ordinary tab navigation does not spam the auth log.
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$logAuth = $referer === '' || stripos($referer, (string)($_SERVER['HTTP_HOST'] ?? '')) === false;

$authenticated = authenticate($logAuth);
if (!$authenticated) {
    exit; // authenticate() already redirected.
}

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------

$requiredFiles = [
    'utility_functions.php',
    'config_functions.php',
    'host_functions.php',
    'hardware_functions.php',
    'dashboard.php',
    'hosts.php',
    'scan.php',
    'settings.php',
    'templates.php',
    'admin_ui.php',
];

foreach ($requiredFiles as $file) {
    // Resolve against this file's directory rather than the CGI working
    // directory, which is not guaranteed to be the script's own directory.
    $path = __DIR__ . '/' . $file;

    if (!is_file($path)) {
        dashboard_log("Missing required file: $file", 'ERROR');
        http_response_code(500);
        echo '<h1>Setup Error</h1><p>Missing required file. Please check the server logs.</p>';
        exit;
    }

    require_once $path;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$validTabs = ['dashboard', 'hosts', 'scan', 'settings', 'templates'];
$activeTab = $_GET['tab'] ?? 'dashboard';
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'dashboard';
}

$message = '';
$error = '';
$scanOutput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // Every state-changing request must carry a valid CSRF token. Without
    // this, any page the operator visits could silently approve a host,
    // rewrite the DHCP configuration or delete a template.
    if (!verifyCsrfToken($_POST)) {
        dashboard_log("Rejected action '$action' with invalid CSRF token", 'WARNING');
        $error = 'Your session has expired or the request could not be verified. Please try again.';
    } elseif ($action === 'logout') {
        logout();
        exit;
    } else {
        dashboard_log("Processing form action: $action");

        try {
            switch ($activeTab) {
                case 'hosts':
                    $result = processHostsActions($action, $_POST);
                    break;

                case 'scan':
                    $result = processScanActions($action, $_POST);
                    break;

                case 'settings':
                    $result = processSettingsActions($action, $_POST);
                    break;

                case 'templates':
                    if ($action === 'download_template') {
                        processDownloadRequest($_POST);
                        exit; // processDownloadRequest() sends the file and exits.
                    }
                    $result = processTemplatesActions($action, $_POST, $_FILES);
                    break;

                case 'dashboard':
                default:
                    $result = processDashboardActions($action, $_POST);
                    break;
            }

            $message = $result['message'] ?? '';
            $error = $result['error'] ?? '';
            $scanOutput = $result['scanOutput'] ?? '';
        } catch (Throwable $e) {
            dashboard_log('Exception processing form action: ' . $e->getMessage(), 'ERROR');
            $error = 'An error occurred processing your request. See the logs for details.';
        }
    }
}

// ---------------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------------

$globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
$hostsConfig = storeLoadHostsConfig();

if ($globalConfig === null) {
    dashboard_log('Failed to load global configuration', 'ERROR');
}

[$pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts] = categorizeHosts($hostsConfig);

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

renderHeader();

if ($message !== '') {
    renderAlert($message, 'success');
}

if ($error !== '') {
    renderAlert($error, 'danger');
}

renderTabsNav($activeTab);

switch ($activeTab) {
    case 'hosts':
        renderHostsContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts);
        break;

    case 'scan':
        renderScanContent($globalConfig, $scanOutput);
        break;

    case 'settings':
        renderSettingsContent($globalConfig);
        break;

    case 'templates':
        renderTemplatesContent($globalConfig);
        break;

    case 'dashboard':
    default:
        renderDashboardContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts);
        break;
}

renderFooter();
