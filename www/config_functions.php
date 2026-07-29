<?php
/**
 * ESXi Auto-deployment - Configuration Functions
 * 
 * Functions for loading, saving, and handling configuration data
 */

/**
 * Load JSON config file
 * 
 * @param string $path Path to JSON config file
 * @return array|null Config array or null if failed
 */
function loadJsonConfig($path) {
    if (!file_exists($path)) {
        logMessage("Config file not found: $path", 'ERROR');
        return null;
    }
    
    $content = file_get_contents($path);
    if ($content === false) {
        logMessage("Failed to read config file: $path", 'ERROR');
        return null;
    }
    
    $config = json_decode($content, true);
    if ($config === null) {
        logMessage("Failed to parse JSON config: $path", 'ERROR');
        return null;
    }
    
    return $config;
}

/**
 * Save JSON config file
 * 
 * @param string $path Path to JSON config file
 * @param array $config Config array
 * @return bool True if successful, false otherwise
 */
function saveJsonConfig($path, $config) {
    try {
        $jsonString = json_encode($config, JSON_PRETTY_PRINT);
        if (file_put_contents($path, $jsonString) === false) {
            logMessage("Failed to write config file: $path", 'ERROR');
            return false;
        }
        return true;
    } catch (Exception $e) {
        logMessage("Exception saving config: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Load secure credentials from a separate config file
 * 
 * @param string $credentialType Type of credential to load (ilo, esxi, db)
 * @return array|null Credentials array or null if not found
 */
function loadSecureCredentials($credentialType = null) {
    $credentialsPath = '/srv/autodeploy/config/credentials.json';
    
    if (!file_exists($credentialsPath)) {
        logMessage("Credentials file not found: $credentialsPath", 'ERROR');
        return null;
    }
    
    $content = file_get_contents($credentialsPath);
    if ($content === false) {
        logMessage("Failed to read credentials file", 'ERROR');
        return null;
    }
    
    $credentials = json_decode($content, true);
    if ($credentials === null) {
        logMessage("Failed to parse credentials JSON", 'ERROR');
        return null;
    }
    
    // If specific credential type requested, return just that section
    if ($credentialType !== null) {
        return isset($credentials[$credentialType]) ? $credentials[$credentialType] : null;
    }
    
    return $credentials;
}

/**
 * Categorize hosts by status
 * 
 * @param array $hostsConfig Full hosts configuration
 * @return array Array containing lists of hosts by status
 */
function categorizeHosts($hostsConfig) {
    $pendingHosts = [];
    $approvedHosts = [];
    $deployingHosts = [];
    $deployedHosts = [];
    
    if (!$hostsConfig || !isset($hostsConfig['hosts'])) {
        return [$pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts];
    }
    
    foreach ($hostsConfig['hosts'] as $host) {
        $status = isset($host['deployment_status']) ? $host['deployment_status'] : 'unknown';
        switch ($status) {
            case 'pending':
                $pendingHosts[] = $host;
                break;
            case 'approved':
                $approvedHosts[] = $host;
                break;
            case 'deploying':
                $deployingHosts[] = $host;
                break;
            case 'deployed':
                $deployedHosts[] = $host;
                break;
            default:
                // Unknown status - treat as pending
                $pendingHosts[] = $host;
                break;
        }
    }
    
    return [$pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts];
}

/**
 * Find a host by MAC address
 * 
 * @param string $mac MAC address to find
 * @param array $hostsConfig Full hosts configuration array
 * @return array|null Host entry or null if not found
 */
function findHostByMac($mac, $hostsConfig) {
    if (!$hostsConfig || !isset($hostsConfig['hosts'])) {
        return null;
    }
    
    $formattedMac = formatMac($mac);
    
    foreach ($hostsConfig['hosts'] as $host) {
        if (formatMac($host['mac_address']) === $formattedMac) {
            return $host;
        }
    }
    
    return null;
}

/**
 * Update host by MAC address
 * 
 * @param string $mac MAC address of host to update
 * @param array $newData New host data
 * @param string $configPath Path to hosts configuration file (optional)
 * @return bool True if successful, false otherwise
 */
function updateHostByMac($mac, $newData, $configPath = null) {
    if ($configPath === null) {
        $globalConfig = loadJsonConfig('/srv/autodeploy/config/global_config.json');
        if (!$globalConfig) {
            return false;
        }
        
        $configPath = $globalConfig['paths']['hosts_config'];
    }
    
    $hostsConfig = loadJsonConfig($configPath);
    if (!$hostsConfig) {
        return false;
    }
    
    $formattedMac = formatMac($mac);
    $updated = false;
    
    foreach ($hostsConfig['hosts'] as &$host) {
        if (formatMac($host['mac_address']) === $formattedMac) {
            // Merge new data with existing host data
            $host = array_merge($host, $newData);
            $updated = true;
            break;
        }
    }
    
    if (!$updated) {
        return false;
    }
    
    return saveJsonConfig($configPath, $hostsConfig);
}