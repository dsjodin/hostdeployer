<?php
/**
 * Dynamic Kickstart Generator for ESXi
 * Detects client MAC address and generates appropriate kickstart file
 * Updated to select template based on deployment type instead of ESXi version
 */

// Include utility functions
require_once '/srv/autodeploy/lib/utils.php';

// Configure error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/srv/autodeploy/logs/php_errors.log');

// Load configurations
$globalConfigPath = '/srv/autodeploy/config/global_config.json';
$hostsConfigPath = '/srv/autodeploy/config/hosts.json';

// Main execution
try {
    logMessage("Kickstart generator started");
    
    // Load configurations
    $globalConfig = loadJsonConfig($globalConfigPath);
    $hostsConfig = loadJsonConfig($hostsConfigPath);
    
    if (!$globalConfig || !$hostsConfig) {
        http_response_code(500);
        echo "ERROR: Configuration loading failed. Check server logs.";
        exit;
    }
    
    // Get client MAC address from query parameter
    if (!isset($_GET['mac']) || empty($_GET['mac'])) {
        http_response_code(400);
        echo "ERROR: Missing MAC address parameter";
        exit;
    }
    $clientMac = formatMac($_GET['mac']);
    logMessage("[DEBUG] - MAC: $clientMac");    
    
    // Get serial number if provided
    $serial = isset($_GET['serial']) ? $_GET['serial'] : null;
    
    // Find host configuration by MAC
    $hostConfig = findHostByMac($clientMac, $hostsConfig);

    // Check if host exists and is either approved or deploying
    if (!$hostConfig || ($hostConfig['deployment_status'] !== 'approved' && $hostConfig['deployment_status'] !== 'deploying')) {
        // Log the attempt
        logMessage("Deployment attempted for unapproved MAC: $clientMac, Status: " . ($hostConfig['deployment_status'] ?? 'unknown'), 'WARNING');
        
        // Return an error or empty kickstart that won't proceed with installation
        header('Content-Type: text/plain');
        echo "# This server is not approved for deployment\n";
        echo "# MAC: $clientMac\n";
        echo "# Please contact the system administrator\n";
        echo "reboot\n";
        exit;
    }
    
    // Update last seen time
    updateHostLastSeen($clientMac, $serial);
    
    // Check if host is pending approval
    if ($hostConfig['deployment_status'] === 'pending') {
        // Host is registered but not approved
        logMessage("Host with MAC $clientMac is pending approval");
        
        // Load waiting template
        $templatePath = $globalConfig['deployment']['waiting_template_path'] ?? '/srv/autodeploy/templates/waiting_template.cfg';
        if (!file_exists($templatePath)) {
            logMessage("Waiting template not found: $templatePath", 'ERROR');
            http_response_code(500);
            echo "ERROR: Waiting template not found";
            exit;
        }
        
        $template = file_get_contents($templatePath);
        $variables = [
            'MAC_ADDRESS' => $clientMac,
            'REGISTERED_TIME' => $hostConfig['registered_time'] ?? date('Y-m-d H:i:s'),
            'SERVER_IP' => $globalConfig['webserver']['ip']
        ];
        
        $waiting = renderTemplate($template, $variables);
        
        header('Content-Type: text/plain');
        echo $waiting;
        exit;
    }
    
    // Check if we need to disable secure boot
    if (isset($globalConfig['security']['secure_boot_enabled']) && 
        $globalConfig['security']['secure_boot_enabled'] && 
        $hostConfig['secure_boot_status'] !== 'disabled') {
        
        disableSecureBoot($clientMac);
    }
    
    // Determine ESXi version to use
    $esxiVersion = $hostConfig['esxi_version'] ?? $globalConfig['deployment']['default_version'];
    
    // Determine deployment type (standard or vcf)
    $deploymentType = $hostConfig['deployment_type'] ?? $globalConfig['deployment']['default_deployment_type'] ?? 'standard';
    
    // Get the appropriate kickstart template path based on deployment type
    $templatePath = null;
    
    // Check if we have a template path for this deployment type
    if (isset($globalConfig['deployment']['kickstart_templates'][$deploymentType])) {
        $templatePath = $globalConfig['deployment']['kickstart_templates'][$deploymentType];
        logMessage("Using $deploymentType template for MAC $clientMac: $templatePath");
    } else {
        // Fall back to standard template if specific type not found
        $templatePath = $globalConfig['deployment']['kickstart_templates']['standard'] ?? 
                       '/srv/autodeploy/templates/kickstart_template_std.cfg';
        logMessage("No template found for deployment type '$deploymentType', falling back to standard for MAC $clientMac", 'WARNING');
    }
    
    if (!file_exists($templatePath)) {
        http_response_code(500);
        echo "ERROR: Kickstart template not found: $templatePath";
        logMessage("Kickstart template not found: $templatePath", 'ERROR');
        exit;
    }

    $template = file_get_contents($templatePath);
    if ($template === false) {
        http_response_code(500);
        echo "ERROR: Failed to read kickstart template";
        logMessage("Failed to read kickstart template", 'ERROR');
        exit;
    }
    
    // Get ESXi root password from credentials.json first, fall back to global config
    $rootPassword = $globalConfig['deployment']['esxi_root_password'] ?? 'VMware1!'; // Default fallback
    
    // Check for a host-specific password in credentials.json
    $esxiCredentials = loadSecureCredentials('esxi', $clientMac);
    if ($esxiCredentials && isset($esxiCredentials['root_password'])) {
        $rootPassword = $esxiCredentials['root_password'];
        logMessage("Using host-specific ESXi root password for $clientMac");
    } else {
        logMessage("Using global ESXi root password");
    }

    $rootPasswordHash = generateEsxiPasswordHash($rootPassword);
    
    // Prepare template variables
    $dnsServers = implode(',', $globalConfig['network']['dns_servers']);
    $serverIp = $globalConfig['webserver']['ip'];
    
    // Basic variables for all deployments
    $variables = [
        'ROOT_PASSWORD_HASH' => $rootPasswordHash,
        'ESXMGMT_IP' => $hostConfig['management_ip'],
        'ESXMGMT_NETMASK' => $hostConfig['management_netmask'],
        'ESXMGMT_GATEWAY' => $hostConfig['management_gateway'],
        'ESXIMGMT_VLANID' => $hostConfig['vlans']['management'] ?? 0,
        'DNS_SERVERS' => $dnsServers,
        'HOSTNAME' => $hostConfig['hostname'],
        'FQDN' => $hostConfig['fqdn'] ?? $hostConfig['hostname'] . '.local',
        'NTP_SERVERS' => implode(',', $globalConfig['network']['ntp_servers']),
        'SERVER_IP' => $serverIp,
        'MAC_ADDRESS' => $hostConfig['mac_address']
    ];
    
    // Add vMotion config for standard deployment if vMotion IP is set
    if ($deploymentType === 'standard' && !empty($hostConfig['vmotion_ip'])) {
        $variables['VMOTION_IP'] = $hostConfig['vmotion_ip'];
        $variables['VMOTION_NETMASK'] = $hostConfig['vmotion_netmask'] ?? '255.255.255.0';
        $variables['VMOTION_VLANID'] = $hostConfig['vlans']['vmotion'] ?? 0;
        $variables['VMOTION_CONFIGURED'] = true;
    } else {
        $variables['VMOTION_CONFIGURED'] = false;
    }
    
    // Render the template
    $kickstart = renderTemplate($template, $variables);
    
    // Update deployment status to ensure it's set to 'deploying' if it's still 'approved'
    if ($hostConfig['deployment_status'] === 'approved') {
        foreach ($hostsConfig['hosts'] as &$host) {
            $hostMac = formatMac($host['mac_address']);
            if ($hostMac === $clientMac) {
                $host['deployment_status'] = 'deploying';
                $host['deployment_started'] = date('Y-m-d H:i:s');
                break;
            }
        }
        saveJsonConfig($hostsConfigPath, $hostsConfig);
    }
    
    // Output the kickstart file
    header('Content-Type: text/plain');
    echo $kickstart;
    
    logMessage("Generated $deploymentType kickstart for $clientMac ({$hostConfig['hostname']})");
    
} catch (Exception $e) {
    logMessage("Exception: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo "ERROR: Internal server error";
}