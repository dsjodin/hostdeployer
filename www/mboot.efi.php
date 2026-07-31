<?php
/**
 * The ESXi loader, for hosts doing UEFI HTTP Boot.
 *
 * DHCP option 67 names one URL for every client in the class, so the firmware
 * cannot ask for a particular version -- and different hosts may be assigned
 * different ESXi releases. This resolves the client, picks its version, and
 * streams that version's loader.
 *
 * mboot then fetches boot.cfg from the same directory as the URL it was loaded
 * from, which is why this lives beside www/boot.cfg.php in the URL space
 * rather than under /esxi/<version>/.
 *
 * Unauthenticated, like the rest of the boot chain: the caller is firmware.
 */

require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/bootcfg.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

const MBOOT_LOG = AUTODEPLOY_LOG_DIR . '/ipxe_boot.log';

/**
 * @param string $reason What went wrong
 * @param int    $status HTTP status
 */
function mbootAbort($reason, $status = 404) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    logMessage("mboot refused: $reason", 'ERROR', MBOOT_LOG);
    echo "$reason\n";
    exit;
}

$mac = formatMac($_GET['mac'] ?? '');
if ($mac === '') {
    // The firmware cannot put its MAC in a URL it was handed by DHCP.
    $mac = getClientMacAddress() ?? '';
}

$globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
if ($globalConfig === null) {
    mbootAbort('the deployment server configuration is unavailable', 500);
}

$version = (string)($globalConfig['deployment']['default_version'] ?? '');

if ($mac !== '') {
    $host = storeFindHost($mac);
    if ($host !== null && ($host['esxi_version'] ?? '') !== '') {
        $version = (string)$host['esxi_version'];
    }
}

// An unknown client still gets the default version's loader. It will be
// refused at boot.cfg, which is the step that can actually check approval --
// and refusing here instead would look to the operator like a firmware fault
// rather than a host awaiting approval.
if ($version === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
    mbootAbort("no usable ESXi version for client " . ($mac !== '' ? $mac : 'unknown'), 500);
}

$imageDir = AUTODEPLOY_ROOT . '/esxi/' . $version;

// Where the loader sits differs between releases and between how the media was
// extracted; boot.ipxe.php resolves the same set of layouts.
$relativeLoader = bootLoaderResolve($imageDir);
$loader = $relativeLoader === null ? null : $imageDir . $relativeLoader;

if ($loader === null) {
    mbootAbort("ESXi version $version has no loader on this server", 500);
}

$size = filesize($loader);
if ($size === false) {
    mbootAbort("could not read the loader for $version", 500);
}

logMessage(
    'Served mboot for ' . ($mac !== '' ? $mac : ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . ", ESXi $version",
    'INFO',
    MBOOT_LOG
);

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Cache-Control: no-store');

// readfile() streams rather than buffering: the loader is small, but the
// output buffer is the last place a multi-megabyte binary should sit.
readfile($loader);
