<?php
/**
 * ESXi Auto-deployment - Hardware Management Functions
 * 
 * Functions for scanning and managing hardware, including iLO interfaces and secure boot
 */

/**
 * Run the iLO scanner script
 * 
 * @return array Output and success status
 */
function runIloScanner() {
    $output = [];
    $result = 0;
    $cmd = "python3 /srv/autodeploy/scripts/ilo_scanner.py 2>&1";
    
    exec($cmd, $output, $result);
    
    return [
        'success' => ($result === 0),
        'output' => implode("\n", $output)
    ];
}

/**
 * Enable or disable secure boot for a host
 * 
 * @param string $mac MAC address of the host
 * @param bool $enable True to enable, false to disable
 * @return array Output and success status
 */
function toggleSecureBoot($mac, $enable = true) {
    $action = $enable ? "enable" : "disable";
    $cmd = "python3 /srv/autodeploy/scripts/secure_boot_manager.py --mac " . escapeshellarg($mac) . " --action $action 2>&1";
    
    $output = [];
    $result = 0;
    exec($cmd, $output, $result);
    
    return [
        'success' => ($result === 0),
        'output' => implode("\n", $output)
    ];
}

/**
 * Check if a system is reachable via ping
 * 
 * @param string $ip IP address to check
 * @param int $timeout Timeout in seconds
 * @return bool True if reachable, false otherwise
 */
function isSystemReachable($ip, $timeout = 1) {
    $cmd = "ping -c 1 -W $timeout " . escapeshellarg($ip) . " > /dev/null 2>&1";
    exec($cmd, $output, $result);
    
    return ($result == 0);
}

/**
 * Get MAC address from a system via ARP
 * 
 * @param string $ip IP address to look up
 * @return string|null MAC address or null if not found
 */
function getMacFromArp($ip) {
    $cmd = "arp -n " . escapeshellarg($ip) . " | grep -v Address";
    exec($cmd, $output, $result);
    
    if ($result !== 0 || empty($output)) {
        return null;
    }
    
    // Extract MAC from output
    $pattern = '/([0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5})/';
    if (preg_match($pattern, $output[0], $matches)) {
        return formatMac($matches[1]);
    }
    
    return null;
}

/**
 * Get detailed system information via iLO
 * 
 * @param string $iloIp IP address of the iLO interface
 * @param string $username iLO username
 * @param string $password iLO password
 * @return array|null System information or null if failed
 */
function getSystemInfoViaIlo($iloIp, $username, $password) {
    $cmd = "python3 /srv/autodeploy/scripts/ilo_info.py --ip " . escapeshellarg($iloIp) . 
           " --user " . escapeshellarg($username) . 
           " --password " . escapeshellarg($password) . 
           " 2>&1";
    
    exec($cmd, $output, $result);
    
    if ($result !== 0) {
        return null;
    }
    
    // Try to parse the JSON output
    $jsonOutput = implode("", $output);
    $data = json_decode($jsonOutput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    return $data;
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
 * Generate a random password
 * 
 * @param int $length Length of the password
 * @return string Random password
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $password = '';
    $max = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    
    return $password;
}