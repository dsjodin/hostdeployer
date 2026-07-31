<?php
/**
 * ESXi Auto-deployment - Configuration Functions
 *
 * loadJsonConfig(), saveJsonConfig(), loadSecureCredentials(), findHostByMac()
 * and updateHostByMac() used to be redefined here, shadowing the copies in
 * lib/utils.php with weaker (non-atomic, non-locking) implementations. They
 * now come from lib/utils.php; only the admin-specific helpers remain.
 */

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/bootcfg.php';

/**
 * Split the host list into buckets by deployment status.
 *
 * @param array|null $hostsConfig Full hosts configuration
 * @return array{0: array, 1: array, 2: array, 3: array} pending, approved, deploying, deployed
 */
function categorizeHosts($hostsConfig) {
    $buckets = [
        'pending'   => [],
        'approved'  => [],
        'deploying' => [],
        'deployed'  => [],
    ];

    if (!is_array($hostsConfig) || !isset($hostsConfig['hosts']) || !is_array($hostsConfig['hosts'])) {
        return array_values($buckets);
    }

    foreach ($hostsConfig['hosts'] as $host) {
        $status = $host['deployment_status'] ?? 'unknown';
        // Unknown statuses are surfaced as pending so they cannot be missed.
        $bucket = isset($buckets[$status]) ? $status : 'pending';
        $buckets[$bucket][] = $host;
    }

    return array_values($buckets);
}

/**
 * Return the list of ESXi versions that are actually installed on disk.
 *
 * A version is usable only when its directory and boot.cfg exist; the UI
 * previously offered versions that would fail at boot time.
 *
 * "Exists" has to mean the same thing here as it does at upload and at boot,
 * which it did not: this looked only at the root, so a medium carrying just
 * efi/boot/boot.cfg booted fine and was still shown as not installed.
 *
 * @param array|null $globalConfig Global configuration
 * @return array<string, array> Version name => version config, augmented with 'available'
 */
function getEsxiVersions($globalConfig) {
    $versions = $globalConfig['deployment']['esxi_versions'] ?? [];
    if (!is_array($versions)) {
        return [];
    }

    foreach ($versions as $name => &$version) {
        $path = $version['path'] ?? (AUTODEPLOY_ROOT . '/esxi/' . $name);
        $version['path'] = $path;
        $version['available'] = is_dir($path) && bootCfgResolve($path) !== null;
    }
    unset($version);

    return $versions;
}
