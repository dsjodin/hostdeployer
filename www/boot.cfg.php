<?php
/**
 * Per-host boot.cfg for mboot.
 *
 * Two callers, one rewrite. A host doing UEFI HTTP Boot fetches mboot.efi
 * straight from DHCP option 67 and mboot then asks for this; a host coming
 * through iPXE chains mboot and gets the same file. Both paths differ in
 * exactly one respect -- where the loader fetches modules from -- so that is a
 * parameter and everything else is shared.
 *
 * That shape is deliberate. via_go had this rewrite implemented twice, once
 * for TFTP and once for HTTP, and they drifted: a fix removing cdromBoot
 * reached only one, so PXE-booted hosts got a different kernel command line to
 * HTTP-booted ones. One function, two callers, no drift.
 *
 * Unauthenticated like the rest of the boot chain, because the caller is a
 * firmware loader with no credentials.
 */

require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/bootcfg.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const BOOTCFG_LOG = AUTODEPLOY_LOG_DIR . '/ipxe_boot.log';

/**
 * Fail with a comment mboot will read and ignore.
 *
 * There is no way to show an error to a UEFI loader, so the useful half of
 * this is the log line: a host that gets an unusable boot.cfg stops, and the
 * reason has to be findable on the server.
 *
 * @param string $reason What went wrong
 * @param int    $status HTTP status
 */
function bootCfgAbort($reason, $status = 404) {
    http_response_code($status);
    logMessage("boot.cfg refused: $reason", 'ERROR', BOOTCFG_LOG);
    echo "# $reason\n";
    exit;
}

$mac = formatMac($_GET['mac'] ?? '');

if ($mac === '') {
    // UEFI HTTP Boot has no way to put the MAC in the URL: the firmware
    // fetches whatever option 67 named, and mboot then asks for boot.cfg
    // beside it. So fall back to identifying the client by its address, the
    // same way generate_kickstart.php does when the loader drops the query
    // string. The iPXE path always passes ?mac= and never reaches this.
    $mac = getClientMacAddress() ?? '';
}

if ($mac === '') {
    bootCfgAbort('could not identify the client: no MAC in the request and none found for '
        . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 400);
}

$globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
if ($globalConfig === null) {
    bootCfgAbort('the deployment server configuration is unavailable', 500);
}

$host = storeFindHost($mac);
if ($host === null) {
    bootCfgAbort("no host is registered with MAC $mac");
}

$status = $host['deployment_status'] ?? 'unknown';
if (bootGateGetDecision($status) !== BOOT_GATE_ALLOW) {
    // A host that is not approved must not be handed something that installs.
    // A deployed one is refused here too: there is nothing useful to say to a
    // finished host in a boot.cfg, and boot.ipxe.php is the endpoint that
    // sends it to its local disk.
    bootCfgAbort("host $mac is not approved for deployment (status: $status)", 403);
}

$version = (string)($host['esxi_version'] ?? ($globalConfig['deployment']['default_version'] ?? ''));
if ($version === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
    bootCfgAbort("invalid ESXi version '$version' configured for $mac", 500);
}

$imageDir = AUTODEPLOY_ROOT . '/esxi/' . $version;
$sourcePath = null;
foreach ([$imageDir . '/boot.cfg', $imageDir . '/efi/boot/boot.cfg'] as $candidate) {
    if (is_file($candidate)) {
        $sourcePath = $candidate;
        break;
    }
}

if ($sourcePath === null) {
    bootCfgAbort("ESXi version $version has no boot.cfg on this server", 500);
}

$source = file_get_contents($sourcePath);
if ($source === false) {
    bootCfgAbort("could not read $sourcePath", 500);
}

if (!bootCfgIsUsable(parseBootCfg($source))) {
    bootCfgAbort("the boot.cfg for $version names no kernel or no modules", 500);
}

$baseUrl = rtrim((string)($globalConfig['webserver']['url'] ?? ''), '/');
if ($baseUrl === '') {
    $baseUrl = 'http://' . ($globalConfig['webserver']['ip'] ?? '');
}

// The host has passed the approval gate, so it may have a kickstart -- and the
// token in this URL is what proves that to /ks.cfg, which otherwise answers
// anything that can name an approved MAC with the ESXi root password hash.
$bootToken = storeIssueBootToken($mac);
if ($bootToken === '') {
    bootCfgAbort("could not issue a boot token for $mac", 500);
}

$rendered = renderBootCfg($source, [
    'prefix'  => bootCfgHTTPPrefix($baseUrl, $version),
    'ks_url'  => $baseUrl . '/ks.cfg?mac=' . $mac . '&t=' . $bootToken,
    'mac'     => $mac,
    'ip'      => $host['management_ip'] ?? '',
    'netmask' => $host['management_netmask'] ?? '255.255.255.0',
    'gateway' => $host['management_gateway'] ?? '',
    'vlan'    => (string)($host['vlans']['management'] ?? 0),
    // Matches --forceunsupportedinstall in the kickstart templates. A field
    // rather than a constant so the decision is visible and reversible.
    'allow_legacy_cpu' => true,
]);

if ($status === 'approved') {
    storeUpdateHost($mac, [
        'deployment_status'  => 'deploying',
        'deployment_started' => date('Y-m-d H:i:s'),
    ]);
}

// The loader has what it needs and is about to fetch the kernel and modules.
storeSetProgress($mac, 15, 'loading the installer');

logMessage("Served boot.cfg for {$host['hostname']} ($mac), ESXi $version", 'INFO', BOOTCFG_LOG);

echo $rendered;
