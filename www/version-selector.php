<?php
// version-selector.php
// Detects MAC address and redirects to appropriate ESXi version bootloader

// Configure error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/srv/autodeploy/logs/php_errors.log');

// Function to log messages
function logMessage($message, $level = 'INFO') {
    $logFile = '/srv/autodeploy/logs/version_selector.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Load configurations
$globalConfigPath = '/srv/autodeploy/config/global_config.json';
$hostsConfigPath = '/srv/autodeploy/config/hosts.json';

// Load global config
$globalConfig = json_decode(file_get_contents($globalConfigPath), true);
if (!$globalConfig) {
    logMessage("Failed to load global configuration", 'ERROR');
    header("HTTP/1.1 500 Internal Server Error");
    exit;
}

// Load hosts config
$hostsConfig = json_decode(file_get_contents($hostsConfigPath), true);
if (!$hostsConfig) {
    logMessage("Failed to load hosts configuration", 'ERROR');
    header("HTTP/1.1 500 Internal Server Error");
    exit;
}

// Function to format MAC consistently
function formatMac($mac) {
    $mac = strtolower(preg_replace('/[:-]/', '', $mac));
    return implode(':', str_split($mac, 2));
}

// Get client MAC address using same methods as in kickstart generator
function getClientMacAddress() {
    // Check if MAC is in query string
    if (isset($_GET['mac']) && !empty($_GET['mac'])) {
        $mac = formatMac($_GET['mac']);
        logMessage("MAC from query string: $mac");
        return $mac;
    }
    
    // Check for MAC in HTTP headers
    if (isset($_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0'])) {
        $mac = formatMac(str_replace('eth0 ', '', $_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0']));
        logMessage("MAC from HTTP header: $mac");
        return $mac;
    }
    
    // Look up MAC via ARP
    $remoteAddr = $_SERVER['REMOTE_ADDR'];
    $arpOutput = shell_exec("arp -n $remoteAddr | grep -v Address");
    if (preg_match('/([0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5})/', $arpOutput, $matches)) {
        $mac = formatMac($matches[1]);
        logMessage("MAC from ARP: $mac ($remoteAddr)");
        return $mac;
    }
    
    logMessage("Could not determine MAC address for $remoteAddr", 'ERROR');
    return null;
}

// Get client MAC
$clientMac = getClientMacAddress();
if (!$clientMac) {
    logMessage("Could not determine client MAC address", 'ERROR');
    header("HTTP/1.1 400 Bad Request");
    exit;
}

// Look up host in configuration
$hostConfig = null;
foreach ($hostsConfig['hosts'] as $host) {
    if (formatMac($host['mac_address']) === $clientMac) {
        $hostConfig = $host;
        break;
    }
}

// Determine ESXi version
$defaultVersion = $globalConfig['deployment']['default_version'] ?? '8.0';
$esxiVersion = ($hostConfig && isset($hostConfig['esxi_version'])) ? $hostConfig['esxi_version'] : $defaultVersion;

// Get bootloader URL for this version
$bootloaderUrl = $globalConfig['deployment']['esxi_versions'][$esxiVersion]['bootloader_url'] ?? 
                 "http://{$globalConfig['webserver']['ip']}/esxi/$esxiVersion/efi/boot/bootx64.efi";

logMessage("Redirecting MAC $clientMac to ESXi version $esxiVersion bootloader: $bootloaderUrl");

// Redirect to appropriate bootloader
header("Location: $bootloaderUrl");
exit;
?>