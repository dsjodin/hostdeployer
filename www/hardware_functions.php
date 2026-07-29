<?php
/**
 * ESXi Auto-deployment - Hardware Management Functions
 *
 * Wrappers around the Python helper scripts (iLO scan, secure boot) and a
 * few low-level host probes.
 */

require_once __DIR__ . '/../lib/utils.php';

/**
 * Run the iLO scanner script.
 *
 * @param int $timeout Maximum run time in seconds
 * @return array{success: bool, output: string}
 */
function runIloScanner($timeout = 900) {
    $command = sprintf(
        'timeout %d python3 %s 2>&1',
        (int)$timeout,
        escapeshellarg(AUTODEPLOY_ROOT . '/scripts/ilo_scanner.py')
    );

    $output = [];
    $returnCode = 1;
    exec($command, $output, $returnCode);

    if ($returnCode === 124) {
        $output[] = "Scan aborted: exceeded the {$timeout}s time limit.";
    }

    return [
        'success' => ($returnCode === 0),
        'output'  => implode("\n", $output),
    ];
}

/**
 * Enable or disable secure boot for a host.
 *
 * @param string $mac    MAC address of the host
 * @param bool   $enable True to enable, false to disable
 * @return array{success: bool, output: string}
 */
function toggleSecureBoot($mac, $enable = true) {
    return runSecureBootManager($mac, $enable);
}

/**
 * Check whether a system responds to ping.
 *
 * @param string $ip      IP address to check
 * @param int    $timeout Timeout in seconds
 * @return bool True when reachable
 */
function isSystemReachable($ip, $timeout = 1) {
    if (!isValidIp($ip)) {
        return false;
    }

    $command = sprintf(
        'ping -c 1 -W %d %s > /dev/null 2>&1',
        max(1, (int)$timeout),
        escapeshellarg($ip)
    );

    $output = [];
    $returnCode = 1;
    exec($command, $output, $returnCode);

    return $returnCode === 0;
}

/**
 * Resolve a MAC address from the local ARP table.
 *
 * @param string $ip IP address to look up
 * @return string|null Normalised MAC or null
 */
function getMacFromArp($ip) {
    return lookupMacViaArp($ip);
}

/**
 * Query detailed system information over iLO.
 *
 * @param string $iloIp    iLO IP address
 * @param string $username iLO username
 * @param string $password iLO password
 * @return array|null Decoded system information, or null on failure
 */
function getSystemInfoViaIlo($iloIp, $username, $password) {
    if (!isValidIp($iloIp)) {
        return null;
    }

    $command = sprintf(
        'python3 %s --ip %s --user %s --password %s 2>/dev/null',
        escapeshellarg(AUTODEPLOY_ROOT . '/scripts/ilo_info.py'),
        escapeshellarg($iloIp),
        escapeshellarg($username),
        escapeshellarg($password)
    );

    $output = [];
    $returnCode = 1;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        return null;
    }

    $data = json_decode(implode('', $output), true);

    return is_array($data) ? $data : null;
}
