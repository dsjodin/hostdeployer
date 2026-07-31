<?php
/**
 * Deployment complete callback.
 *
 * Called by the freshly installed ESXi host at the end of %firstboot to:
 *   1. flip the host's deployment status to "deployed"
 *   2. re-enable secure boot when the deployment turned it off
 */

require_once __DIR__ . '/../lib/store.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

const DEPLOYMENT_LOG = AUTODEPLOY_LOG_DIR . '/deployment.log';

/**
 * @param string $message Message
 * @param string $level   Level
 */
function deployLog($message, $level = 'INFO') {
    logMessage($message, $level, DEPLOYMENT_LOG);
}

/**
 * Re-enable secure boot for a host.
 *
 * @param string $mac MAC address
 * @return bool True on success
 */
function enableSecureBoot($mac) {
    // The helper needs the python redfish module; check once and report a
    // useful message instead of a generic non-zero exit.
    exec("python3 -c 'import redfish' 2>&1", $checkOutput, $checkCode);

    if ($checkCode !== 0) {
        deployLog('Python redfish module is not installed (pip3 install redfish); skipping secure boot', 'ERROR');
        return false;
    }

    return runSecureBootManager($mac, true)['success'];
}

header('Content-Type: text/plain; charset=utf-8');

try {
    deployLog('Deployment complete callback started');

    $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
    if ($globalConfig === null) {
        http_response_code(500);
        deployLog('Failed to load global configuration', 'ERROR');
        exit('ERROR: failed to load global configuration');
    }

    $mac = formatMac($_GET['mac'] ?? '');
    if ($mac === '') {
        http_response_code(400);
        deployLog('Missing or malformed MAC address parameter', 'ERROR');
        exit('ERROR: missing MAC address parameter');
    }

    // Marking a host deployed is what stops it installing: boot.ipxe.php sends
    // a deployed host to its local disk. Anything on the network could do that
    // to any host it could name, and the result looked like an installation
    // that hung rather than one that was interfered with.
    if (!storeVerifyBootToken($mac, (string)($_GET['t'] ?? ''))) {
        http_response_code(403);
        deployLog("Completion reported for $mac with a missing or invalid boot token", 'WARNING');
        exit('ERROR: not authorised');
    }

    deployLog("Processing deployment completion for MAC: $mac");

    $updated = storeUpdateHost($mac, [
        'deployment_status' => 'deployed',
        'deployment_time'   => date('Y-m-d H:i:s'),
        'progress'          => 100,
        'progress_text'     => 'deployed',
    ]);

    if (!$updated) {
        http_response_code(404);
        deployLog("Host with MAC $mac not found in configuration", 'ERROR');
        exit('ERROR: host not found');
    }

    deployLog("Host $mac marked as deployed");

    // The deployment is over, so the token that belonged to it is retired. A
    // host that is reinstalled goes through the boot chain again and is issued
    // a new one; nothing that was left in a log or a %firstboot script keeps
    // working afterwards.
    storeClearBootToken($mac);

    if (empty($globalConfig['security']['secure_boot_enabled'])) {
        deployLog("Secure boot is disabled in global config, skipping for $mac");
        exit('SUCCESS: deployment complete (secure boot not enabled in config)');
    }

    // Give the host a moment to finish booting before touching BIOS settings.
    sleep(10);

    if (enableSecureBoot($mac)) {
        storeUpdateHost($mac, ['secure_boot_status' => 'enabled']);
        deployLog("Successfully re-enabled secure boot for $mac");
        exit('SUCCESS: deployment complete and secure boot enabled');
    }

    deployLog("Failed to re-enable secure boot for $mac", 'WARNING');
    exit('WARNING: deployment complete but secure boot could not be enabled');
} catch (Throwable $e) {
    deployLog('Exception: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo 'ERROR: internal server error';
}
