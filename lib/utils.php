<?php
/**
 * Common utility functions for ESXi Autodeploy
 */

/**
 * Load secure credentials from a separate config file
 * 
 * @param string $credentialType Type of credential to load (ilo, esxi, db)
 * @param string|null $macAddress MAC address for host-specific credentials
 * @return array|null Credentials array or null if not found
 */
function loadSecureCredentials($credentialType = null, $macAddress = null) {
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
    
    // If specific credential type requested
    if ($credentialType !== null) {
        if (!isset($credentials[$credentialType])) {
            return null;
        }
        
        // If MAC address is provided, look for host-specific credentials
        if ($macAddress !== null) {
            $macFormatted = formatMac($macAddress);
            
            // Check for host-specific credentials
            if (isset($credentials[$credentialType]['hosts'][$macFormatted])) {
                // Merge global and host-specific credentials, with host-specific taking precedence
                return array_merge(
                    $credentials[$credentialType],
                    $credentials[$credentialType]['hosts'][$macFormatted]
                );
            }
        }
        
        return $credentials[$credentialType];
    }
    
    return $credentials;
}

/**
 * Get client's MAC address through various methods
 * 
 * @return string|null MAC address or null if not found
 */
function getClientMacAddress() {
    // Check if MAC is in query string
    if (isset($_GET['mac']) && !empty($_GET['mac'])) {
        return formatMac($_GET['mac']);
    }
    
    // Check for MAC in HTTP headers
    if (isset($_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0'])) {
        return formatMac(str_replace('eth0 ', '', $_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0']));
    }
    
    // Look up MAC via ARP
    $remoteAddr = $_SERVER['REMOTE_ADDR'];
    $arpOutput = shell_exec("arp -n $remoteAddr | grep -v Address");
    if (preg_match('/([0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5})/', $arpOutput, $matches)) {
        return formatMac($matches[1]);
    }
    
    return null;
}

/**
 * Generate ESXi compatible password hash
 * 
 * @param string $password Plain text password
 * @return string ESXi compatible password hash
 */
function generateEsxiPasswordHash($password) {
    // ESXi uses SHA-512 with salt
    $salt = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 8);
    return crypt($password, '$6$' . $salt);
}

/**
 * Format MAC address consistently
 * 
 * @param string $mac MAC address in any format
 * @return string Formatted MAC address (xx:xx:xx:xx:xx:xx)
 */
function formatMac($mac) {
    // Remove any colons or dashes and convert to lowercase
    $mac = strtolower(preg_replace('/[:-]/', '', $mac));
    
    // Format as colon-separated pairs
    return implode(':', str_split($mac, 2));
}

/**
 * Log message to file
 * 
 * @param string $message Message to log
 * @param string $level Log level (INFO, WARNING, ERROR)
 * @param string $logFile Path to log file (optional)
 */
function logMessage($message, $level = 'INFO', $logFile = null) {
    if ($logFile === null) {
        // Default log file based on script name
        $scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown', '.php');
        $logFile = "/srv/autodeploy/logs/{$scriptName}.log";
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    // Ensure log directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

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
 * Find host configuration by MAC address
 * 
 * @param string $macAddress Formatted MAC address to search for
 * @param array $hostsConfig Hosts configuration array
 * @return array|null Host configuration array or null if not found
 */
function findHostByMac($macAddress, $hostsConfig) {
    if (!isset($hostsConfig['hosts']) || !is_array($hostsConfig['hosts'])) {
        logMessage("Invalid hosts configuration structure", 'ERROR');
        return null;
    }
    
    $macAddress = formatMac($macAddress); // Ensure consistent format
    
    foreach ($hostsConfig['hosts'] as $host) {
        if (formatMac($host['mac_address']) === $macAddress) {
            return $host;
        }
    }
    
    logMessage("Host with MAC $macAddress not found", 'WARNING');
    return null;
}

/**
 * Update host's last seen timestamp and optionally serial number
 * 
 * @param string $macAddress MAC address of the host to update
 * @param string|null $serialNumber Optional serial number to update
 * @return bool True if successful, false otherwise
 */
function updateHostLastSeen($macAddress, $serialNumber = null) {
    $hostsConfigPath = '/srv/autodeploy/config/hosts.json';
    $hostsConfig = loadJsonConfig($hostsConfigPath);
    
    if (!$hostsConfig) {
        logMessage("Failed to load hosts configuration for updating last seen", 'ERROR');
        return false;
    }
    
    $macAddress = formatMac($macAddress); // Ensure consistent format
    $updated = false;
    
    foreach ($hostsConfig['hosts'] as &$host) {
        if (formatMac($host['mac_address']) === $macAddress) {
            $host['last_seen'] = date('Y-m-d H:i:s');
            
            // Update serial number if provided and different from current
            if ($serialNumber !== null && 
                (!isset($host['serial_number']) || $host['serial_number'] !== $serialNumber)) {
                $host['serial_number'] = $serialNumber;
                logMessage("Updated serial number for $macAddress: $serialNumber");
            }
            
            $updated = true;
            break;
        }
    }
    
    if ($updated) {
        return saveJsonConfig($hostsConfigPath, $hostsConfig);
    }
    
    logMessage("Host with MAC $macAddress not found for updating last seen", 'WARNING');
    return false;
}

/**
 * Render a template by replacing placeholders with values
 * 
 * @param string $template Template content with placeholders
 * @param array $variables Key-value pairs for replacement
 * @return string Rendered template
 */
function renderTemplate($template, $variables) {
    // Process conditionals first
    $template = processConditionals($template, $variables);
    
    // Replace variables
    foreach ($variables as $key => $value) {
        if (is_bool($value)) {
            // Boolean values should have been handled by conditionals
            continue;
        } elseif (is_array($value)) {
            // Skip arrays, they should be processed specifically
            continue;
        } else {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
    }
    
    return $template;
}

/**
 * Process conditional sections in a template
 * 
 * @param string $template Template with conditionals
 * @param array $variables Variables to evaluate conditionals
 * @return string Processed template
 */
function processConditionals($template, $variables) {
    // Process hashtag-style conditionals ({{#VARIABLE}}...{{/VARIABLE}})
    $pattern = '/\{\{#([A-Z_]+)\}\}(.*?)\{\{\/\1\}\}/s';
    $template = preg_replace_callback($pattern, function($matches) use ($variables) {
        $condition = $matches[1];
        $content = $matches[2];
        
        // Check if condition is true (exists and is truthy)
        if (isset($variables[$condition]) && $variables[$condition]) {
            return $content;
        }
        
        return ''; // Remove section if condition is false
    }, $template);
    
    // Process IF conditionals
    $pattern = '/\{\{IF\s+([A-Z_]+)\}\}(.*?)\{\{ENDIF\}\}/s';
    $template = preg_replace_callback($pattern, function($matches) use ($variables) {
        $condition = $matches[1];
        $content = $matches[2];
        
        // Check if condition is true (exists and is truthy)
        if (isset($variables[$condition]) && $variables[$condition]) {
            return $content;
        }
        
        return ''; // Remove section if condition is false
    }, $template);
    
    // Process IF-ELSE conditionals
    $pattern = '/\{\{IF\s+([A-Z_]+)\}\}(.*?)\{\{ELSE\}\}(.*?)\{\{ENDIF\}\}/s';
    $template = preg_replace_callback($pattern, function($matches) use ($variables) {
        $condition = $matches[1];
        $trueContent = $matches[2];
        $falseContent = $matches[3];
        
        // Check if condition is true (exists and is truthy)
        if (isset($variables[$condition]) && $variables[$condition]) {
            return $trueContent;
        }
        
        return $falseContent; // Use else section if condition is false
    }, $template);
    
    return $template;
}

/**
 * Attempt to disable secure boot on server if supported
 * 
 * @param string $macAddress MAC address of the host
 * @return bool True if successful, false otherwise
 */
function disableSecureBoot($macAddress) {
    // Simply execute the secure_boot_manager.py script
    $macFormatted = escapeshellarg(formatMac($macAddress));
    $command = "python3 /srv/autodeploy/scripts/secure_boot_manager.py --mac $macFormatted --action disable 2>&1";
    
    logMessage("Executing command: $command");
    
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        logMessage("Successfully disabled secure boot for $macAddress");
        return true;
    } else {
        logMessage("Failed to disable secure boot for $macAddress: " . implode("\n", $output), 'ERROR');
        return false;
    }
}