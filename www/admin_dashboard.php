<?php
/**
 * ESXi Auto-deployment Admin Dashboard
 *
 * This is the central control interface for the ESXi deployment system.
 * It uses a modular approach with separate files for each tab.
 */

// Define a constant to prevent direct access to tab files
define('ADMIN_DASHBOARD', true);

// Configure error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/srv/autodeploy/logs/php_errors.log');

// Enable error reporting during development
// Comment this out in production
// error_reporting(E_ALL);

// Log important information
function dashboard_log($message, $level = 'INFO') {
    $logFile = '/srv/autodeploy/logs/admin_dashboard.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Include authentication library first since we need it immediately
require_once '/srv/autodeploy/lib/auth.php';

// Authentication
try {
    // Only log successful authentication on initial page load (when no tab is specified)
    $logAuth = !isset($_SERVER['HTTP_REFERER']) || !strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
    
    $authenticated = authenticate($logAuth);
    if (!$authenticated) {
        // Auth function handles the redirect to login page
        exit;
    }
    
    // Only log this on initial authentication, not on subsequent page views
    if ($logAuth) {
        dashboard_log("User {$_SESSION['username']} authenticated successfully", 'INFO');
    }
} catch (Exception $e) {
    dashboard_log("Authentication exception: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo "<h1>Authentication Error</h1>";
    echo "<p>There was a problem with the authentication system.</p>";
    echo "<p>Please check server logs for details.</p>";
    exit;
}

// Verify required files and paths after authentication
$requiredFiles = [
    // Shared function files
    'utility_functions.php' => 'Utility Functions',
    'config_functions.php' => 'Configuration Functions',
    'host_functions.php' => 'Host Management Functions',
    'hardware_functions.php' => 'Hardware Functions',
    // Tab files
    'dashboard.php' => 'Dashboard Tab',
    'hosts.php' => 'Hosts Tab',
    'scan.php' => 'Scan Tab',
    'settings.php' => 'Settings Tab',
    'templates.php' => 'Templates Tab',
    // UI components
    'admin_ui.php' => 'Admin UI'
];



foreach ($requiredFiles as $file => $description) {
    if (!file_exists($file)) {
        dashboard_log("Missing required file: $file ($description)", 'ERROR');
        http_response_code(500);
        echo "<h1>Setup Error</h1>";
        echo "<p>Missing required file: $description</p>";
        echo "<p>Please check server configuration and logs.</p>";
        exit;
    }
}

// Include required files
try {
    // First include utility functions as other files depend on them
    require_once 'utility_functions.php';
    
    // Include shared function files
    require_once 'config_functions.php';
    require_once 'host_functions.php';
    require_once 'hardware_functions.php';
    
    // Include tab files
    require_once 'dashboard.php';
    require_once 'hosts.php';
    require_once 'scan.php';
    require_once 'settings.php';
    require_once 'templates.php';
    
    // Include UI components
    require_once 'admin_ui.php';
} catch (Exception $e) {
    dashboard_log("Exception loading required files: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo "<h1>Setup Error</h1>";
    echo "<p>Error loading required files. See logs for details.</p>";
    exit;
}

// Process form submissions
$message = '';
$error = '';
$scanOutput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check for logout action
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        logout();
        exit;
    }
    
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    dashboard_log("Processing form action: $action", 'INFO');
    
    // Process the action through the appropriate handler based on current tab
    try {
        $activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        
        // Determine which tab's action processor to use
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
                
            case 'templates': // Add this case
                // Handle download requests separately
                if ($action === 'download_template') {
                    processDownloadRequest($_POST);
                    exit; // Download handling will exit
                }
                      // Process other template actions
            $result = processTemplatesActions($action, $_POST, $_FILES);
            break;
            
        case 'dashboard':
        default:
            $result = processDashboardActions($action, $_POST);
            break;
    }
        
        // Extract result values
        if (isset($result['message'])) {
            $message = $result['message'];
        }
        
        if (isset($result['error'])) {
            $error = $result['error'];
        }
        
        if (isset($result['scanOutput'])) {
            $scanOutput = $result['scanOutput'];
        }
    } catch (Exception $e) {
        dashboard_log("Exception processing form action: " . $e->getMessage(), 'ERROR');
        $error = "An error occurred processing your request. See logs for details.";
    }
}

// Load configurations
try {
    $globalConfig = loadJsonConfig('/srv/autodeploy/config/global_config.json');
    $hostsConfig = loadJsonConfig('/srv/autodeploy/config/hosts.json');
} catch (Exception $e) {
    dashboard_log("Exception loading configurations: " . $e->getMessage(), 'ERROR');
    $error = "Failed to load system configurations";
    $globalConfig = null;
    $hostsConfig = null;
}

// Process data for display
if ($hostsConfig) {
    list($pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts) = categorizeHosts($hostsConfig);
} else {
    $pendingHosts = $approvedHosts = $deployingHosts = $deployedHosts = [];
}

// Get active tab (default to dashboard)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$validTabs = ['dashboard', 'hosts', 'scan', 'settings', 'templates'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'dashboard';
}

// Render the page
renderHeader();

// Show messages if any
if ($message) {
    renderAlert($message, 'success');
}

if ($error) {
    renderAlert($error, 'danger');
}

// Render tabs navigation
renderTabsNav($activeTab);

// Render active tab content
switch ($activeTab) {
    case 'dashboard':
        renderDashboardContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts);
        break;
        
    case 'hosts':
        renderHostsContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts);
        break;
        
    case 'scan':
        renderScanContent($globalConfig, $scanOutput);
        break;
        
    case 'settings':
        renderSettingsContent($globalConfig);
        break;

    case 'templates': // Add this case
        renderTemplatesContent($globalConfig);
        break;
}

// Render the footer
renderFooter();