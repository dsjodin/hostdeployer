<?php
/**
 * ESXi Auto-deployment Admin - Utility Functions
 *
 * The shared helpers (formatMac, logMessage, renderTemplate, isValidIp, ...)
 * used to be duplicated here with subtly different behaviour from the copies
 * in lib/utils.php. They now live in exactly one place; this file only keeps
 * the handful of helpers that are specific to the admin UI.
 */

require_once __DIR__ . '/../lib/utils.php';

/**
 * Convert an IP address to an integer, returning 0 for invalid input.
 *
 * @param string $ip IP address
 * @return int IP as integer
 */
function ip2long_safe($ip) {
    $long = ip2long((string)$ip);
    return $long === false ? 0 : $long;
}

/**
 * Check whether an IP falls inside a CIDR block or a start-end range.
 *
 * @param string $ip    IP address to check
 * @param string $range CIDR ("192.168.1.0/24") or range ("192.168.1.1-192.168.1.254")
 * @return bool True when the IP is inside the range
 */
function isIpInRange($ip, $range) {
    if (!isValidIpv4($ip)) {
        return false;
    }

    // CIDR notation
    if (strpos($range, '/') !== false) {
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);

        if (!isValidIpv4($subnet) || !is_numeric($bits)) {
            return false;
        }

        $bits = (int)$bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ((ip2long_safe($ip) & $mask) === (ip2long_safe($subnet) & $mask));
    }

    // Start-end range
    if (strpos($range, '-') !== false) {
        [$start, $end] = array_pad(explode('-', $range, 2), 2, null);
        $start = trim((string)$start);
        $end = trim((string)$end);

        if (!isValidIpv4($start) || !isValidIpv4($end)) {
            return false;
        }

        $ipLong = ip2long_safe($ip);

        return $ipLong >= ip2long_safe($start) && $ipLong <= ip2long_safe($end);
    }

    return $ip === $range;
}
