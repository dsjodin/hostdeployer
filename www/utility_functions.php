<?php
/**
 * ESXi Auto-deployment - Utility Functions
 * 
 * General utility functions used throughout the application
 */

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
 * Validate IP address
 * 
 * @param string $ip IP address to validate
 * @return bool True if valid, false otherwise
 */
function isValidIp($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP);
}

/**
 * Render a template with variables
 * 
 * @param string $template Template string
 * @param array $variables Variables to replace in template
 * @return string Rendered template
 */
function renderTemplate($template, $variables) {
    // Simple template rendering with variable replacement
    $result = $template;
    
    // Replace simple variables
    foreach ($variables as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            $result = str_replace('{{'.$key.'}}', $value, $result);
        }
    }
    
    // Handle arrays and conditional blocks (basic implementation)
    // This is just a simple example - for complex templates use a proper template engine
    $result = preg_replace_callback('/{{#([a-zA-Z0-9_]+)}}(.*?){{\/\1}}/s', function($matches) use ($variables) {
        $key = $matches[1];
        $content = $matches[2];
        
        if (!isset($variables[$key]) || empty($variables[$key])) {
            return '';
        }
        
        if (is_array($variables[$key])) {
            if (isset($variables[$key][0])) {
                // Handle array of items
                $result = '';
                foreach ($variables[$key] as $item) {
                    $itemResult = $content;
                    // Replace {{.}} with item value if item is scalar
                    if (is_scalar($item)) {
                        $itemResult = str_replace('{{.}}', $item, $itemResult);
                    } 
                    // Replace {{key}} with value from item if item is array/object
                    elseif (is_array($item)) {
                        foreach ($item as $itemKey => $itemValue) {
                            if (is_scalar($itemValue)) {
                                $itemResult = str_replace('{{'.$itemKey.'}}', $itemValue, $itemResult);
                            }
                        }
                    }
                    $result .= $itemResult;
                }
                return $result;
            } else {
                // Associative array - consider it as an object
                $result = $content;
                foreach ($variables[$key] as $objKey => $objValue) {
                    if (is_scalar($objValue)) {
                        $result = str_replace('{{'.$objKey.'}}', $objValue, $result);
                    }
                }
                return $result;
            }
        } else {
            // Treat as boolean - show content if true/non-empty
            return $variables[$key] ? $content : '';
        }
    }, $result);
    
    return $result;
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
 * Get readable file size
 * 
 * @param int $bytes Size in bytes
 * @param int $precision Decimal precision
 * @return string Formatted file size
 */
function getReadableFileSize($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Convert IP to integer
 * 
 * @param string $ip IP address
 * @return int IP as integer
 */
function ip2long_safe($ip) {
    $long = ip2long($ip);
    if ($long === false) {
        return 0;
    }
    return $long;
}

/**
 * Check if IP is in range
 * 
 * @param string $ip IP address to check
 * @param string $range IP range (192.168.1.0/24 or 192.168.1.1-192.168.1.254)
 * @return bool True if in range, false otherwise
 */
function isIpInRange($ip, $range) {
    // Check for CIDR format
    if (strpos($range, '/') !== false) {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }
    
    // Check for range format
    if (strpos($range, '-') !== false) {
        list($start, $end) = explode('-', $range);
        $ip = ip2long_safe($ip);
        $start = ip2long_safe($start);
        $end = ip2long_safe($end);
        return ($ip >= $start && $ip <= $end);
    }
    
    // Exact match
    return $ip === $range;
}