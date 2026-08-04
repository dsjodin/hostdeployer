<?php
/**
 * boot.ipxe.php - dynamic iPXE script generator.
 *
 * Called by ipxe/boot.ipxe with ?mac=<client mac>. Emits a complete iPXE
 * script that loads the ESXi mboot kernel plus every module listed in the
 * version's boot.cfg, and points the installer at /ks.cfg.
 */

require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/bootcfg.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

header('Content-Type: text/plain; charset=utf-8');
// iPXE re-fetches this script on every retry; it must never be cached.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

const IPXE_LOG = AUTODEPLOY_LOG_DIR . '/ipxe_boot.log';

/**
 * Log to the iPXE boot log.
 *
 * @param string $message Message
 * @param string $level   Level
 */
function ipxeLog($message, $level = 'INFO') {
    logMessage($message, $level, IPXE_LOG);
}

/**
 * Emit an iPXE script that shows a message and falls through to local boot.
 *
 * @param string[] $lines  Messages to display on the console
 * @param int      $sleep  Seconds to pause before exiting
 */
function ipxeFail(array $lines, $sleep = 10) {
    echo "#!ipxe\n";
    foreach ($lines as $line) {
        echo 'echo ' . sanitizeIpxeText($line) . "\n";
    }
    echo "sleep $sleep\n";
    // Non-zero exit tells iPXE to continue with the next boot device.
    echo "exit 1\n";
    exit;
}

/**
 * Strip characters that would break out of an iPXE echo line.
 *
 * @param string $text Text to sanitise
 * @return string Safe single-line text
 */
function sanitizeIpxeText($text) {
    return preg_replace('/[^\x20-\x7E]/', '', str_replace(["\r", "\n"], ' ', (string)$text));
}

// ---------------------------------------------------------------------------
// Identify the client
// ---------------------------------------------------------------------------

$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$mac = formatMac($_GET['mac'] ?? '');

if ($mac === '') {
    ipxeLog("Missing or malformed MAC address in request from $clientIP", 'ERROR');
    ipxeFail(['ERROR: no valid MAC address supplied'], 5);
}

ipxeLog("iPXE boot request from MAC: $mac, IP: $clientIP");

// ---------------------------------------------------------------------------
// Load configuration
// ---------------------------------------------------------------------------

$globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);

if ($globalConfig === null || !storeIsReachable()) {
    ipxeLog('Failed to load server configuration', 'ERROR');
    ipxeFail(['ERROR: deployment server configuration is unavailable'], 5);
}

$defaultVersion = $globalConfig['deployment']['default_version'] ?? '';
$autoRegistration = $globalConfig['deployment']['auto_registration'] ?? [];
$autoRegistrationEnabled = !empty($autoRegistration['enabled']);

// An indexed lookup, not a scan. This endpoint is hit once per booting host
// and then again on every retry of a 60-second poll, so it is the last place
// that should be reading the whole estate to find one record.
$host = storeFindHost($mac);

// ---------------------------------------------------------------------------
// Fall back to the serial number
// ---------------------------------------------------------------------------
//
// ipxe/boot.ipxe sends ${smbios/serial} alongside the MAC, and until now
// nothing looked at it. It is the join between the two halves of the system:
// the iLO scan registers a machine by serial and by whichever MACs the BMC
// chose to enumerate, and the machine then boots from whichever port its boot
// order picked -- an add-in card, a re-cabled port, a NIC the BMC does not
// list. Those two sets do not always overlap, and when they do not, a host
// that was discovered, named and approved arrives here looking brand new.
//
// Matching on the serial recognises it, and the port it booted from is
// recorded so the next boot resolves by MAC without this detour.

$serial = (string)($_GET['serial'] ?? '');

if ($host === null && $serial !== '') {
    $host = storeFindHostBySerial($serial);

    if ($host !== null) {
        ipxeLog("Matched $mac to {$host['hostname']} by serial $serial");

        // No conflict is possible here: nothing owns this MAC, or the lookup
        // above would have found it.
        storeAttachMac($host['mac_address'] ?? '', $mac);
    }
}

// ---------------------------------------------------------------------------
// Auto-registration of unknown hosts
// ---------------------------------------------------------------------------

if ($host === null && $autoRegistrationEnabled) {
    ipxeLog("Unknown host with MAC: $mac - auto-registering");

    $defaultStatus = ($autoRegistration['default_status'] ?? 'pending') === 'approved' ? 'approved' : 'pending';
    $now = date('Y-m-d H:i:s');

    $newHost = [
        'mac_address'        => $mac,
        'hostname'           => 'esxi-' . substr(str_replace(':', '', $mac), -6),
        'esxi_version'       => $defaultVersion,
        'serial_number'      => '',
        'ilo_ip'             => '',
        'management_ip'      => '',
        'management_netmask' => '',
        'management_gateway' => '',
        'fqdn'               => '',
        'vlans'              => ['management' => 0, 'vmotion' => 0, 'storage' => 0],
        'datastore'          => ['name' => 'datastore1', 'drives' => []],
        'deployment_type'    => $globalConfig['deployment']['default_deployment_type'] ?? 'standard',
        'secure_boot_status' => 'unknown',
        'deployment_status'  => $defaultStatus,
        'registered_time'    => $now,
        'last_seen'          => $now,
    ];

    // storeAddHost() re-checks for an existing record inside the lock: two
    // NICs of the same server can hit this endpoint simultaneously and
    // previously produced duplicate entries (or lost one of the writes).
    $registered = storeAddHost($newHost);

    if ($registered) {
        ipxeLog("Successfully auto-registered host with MAC: $mac");

        $notifyTo = $autoRegistration['notification_email'] ?? '';
        if ($notifyTo !== '' && filter_var($notifyTo, FILTER_VALIDATE_EMAIL)) {
            $subject = 'New server auto-registered: ' . $mac;
            $body = "A new server with MAC address $mac has been auto-registered.\n\n"
                . "Default hostname: {$newHost['hostname']}\n"
                . "Registration time: {$newHost['registered_time']}\n"
                . "IP address: $clientIP\n\n"
                . 'Please review and approve this server in the admin dashboard.';

            if (!@mail($notifyTo, $subject, $body)) {
                ipxeLog('Failed to send auto-registration notification email', 'WARNING');
            }
        }
    }

    // Re-read so we act on the record that actually landed in the inventory:
    // storeAddHost() declines when another NIC of the same server registered
    // it first, and that host is the one to boot.
    $host = storeFindHost($mac);
}

// ---------------------------------------------------------------------------
// Approval gate
// ---------------------------------------------------------------------------

$retries = max(0, (int)($_GET['retry'] ?? 0));
$retryDelay = max(10, (int)($autoRegistration['retry_interval'] ?? 60));
$maxWaitTime = max($retryDelay, (int)($autoRegistration['max_wait_time'] ?? 7200));
$maxRetries = (int)floor($maxWaitTime / $retryDelay);

/**
 * Emit a "wait and retry" iPXE script, or give up once the budget is spent.
 *
 * @param string[] $lines      Console messages
 * @param int      $retries    Retries used so far
 * @param int      $maxRetries Retry budget
 * @param int      $delay      Seconds between retries
 * @param string   $mac        Client MAC
 */
function ipxeRetryOrGiveUp(array $lines, $retries, $maxRetries, $delay, $mac) {
    if ($retries >= $maxRetries) {
        $lines[] = "Maximum wait time reached after $maxRetries attempts";
        $lines[] = 'Please contact your system administrator';
        $lines[] = 'Booting from local disk';
        ipxeFail($lines, 5);
    }

    $next = $retries + 1;
    $remainingMinutes = (int)floor((($maxRetries - $retries) * $delay) / 60);

    // Build the retry URL from the request the client actually made, so the
    // loop survives being reached via a hostname or an alternate address.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hostHeader = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? 'localhost');
    $hostHeader = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $hostHeader);
    // $mac is already normalised to [0-9a-f:] by formatMac(), so it needs no
    // escaping; percent-encoding the colons only confuses the iPXE parser.
    $retryUrl = sprintf('%s://%s/boot.ipxe.php?mac=%s&retry=%d', $scheme, $hostHeader, $mac, $next);

    echo "#!ipxe\n";
    foreach ($lines as $line) {
        echo 'echo ' . sanitizeIpxeText($line) . "\n";
    }
    echo 'echo Retry attempt ' . $next . ' of ' . $maxRetries . "\n";
    echo 'echo Will keep checking for approximately ' . $remainingMinutes . " minutes\n";
    echo 'sleep ' . $delay . "\n";
    echo 'chain ' . sanitizeIpxeText($retryUrl) . "\n";
    exit;
}

if ($host === null) {
    ipxeLog("Unknown host with MAC: $mac - auto-registration disabled", 'WARNING');
    ipxeRetryOrGiveUp([
        "Unknown server with MAC $mac",
        'This server is not registered in the deployment system',
    ], $retries, min($maxRetries, 5), $retryDelay, $mac);
}

$hostname = $host['hostname'] ?? 'unknown';
$deploymentStatus = $host['deployment_status'] ?? 'unknown';

$gate = bootGateGetDecision($deploymentStatus);

if ($gate === BOOT_GATE_DEPLOYED) {
    // Installation already finished; do not reinstall on every reboot.
    ipxeLog("Host $hostname ($mac) is already deployed - booting from local disk");
    ipxeFail([
        "Server $hostname is already deployed",
        'Booting from local disk',
    ], 3);
}

if ($gate !== BOOT_GATE_ALLOW) {
    ipxeLog("Host $hostname ($mac) is not approved for deployment: $deploymentStatus", 'WARNING');
    ipxeRetryOrGiveUp([
        "Server $hostname with MAC $mac is awaiting approval",
        "Current status: $deploymentStatus",
    ], $retries, $maxRetries, $retryDelay, $mac);
}

// ---------------------------------------------------------------------------
// Resolve the ESXi image
// ---------------------------------------------------------------------------

$esxiVersion = (string)($host['esxi_version'] ?? $defaultVersion);

// The version name becomes part of a filesystem path and a URL.
if ($esxiVersion === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $esxiVersion)) {
    ipxeLog("Invalid ESXi version '$esxiVersion' for $mac", 'ERROR');
    ipxeFail(['ERROR: invalid ESXi version configured for this host'], 5);
}

$esxiPath = AUTODEPLOY_ROOT . '/esxi/' . $esxiVersion;
$bootCfgPath = is_dir($esxiPath) ? bootCfgResolve($esxiPath) : null;

if ($bootCfgPath === null) {
    ipxeLog("ESXi version $esxiVersion not installed at $esxiPath", 'ERROR');
    ipxeFail([
        "ERROR: ESXi version $esxiVersion is not available on the deployment server",
        'Please contact your system administrator',
    ], 5);
}

$bootCfg = file_get_contents($bootCfgPath);
if ($bootCfg === false) {
    ipxeLog("Failed to read $bootCfgPath", 'ERROR');
    ipxeFail(['ERROR: could not read the ESXi boot configuration'], 5);
}

$parsedBootCfg = parseBootCfg($bootCfg);

if (!bootCfgIsUsable($parsedBootCfg)) {
    ipxeLog("Invalid boot.cfg for ESXi $esxiVersion (kernel or modules missing)", 'ERROR');
    ipxeFail(["ERROR: invalid boot configuration for ESXi $esxiVersion"], 5);
}

$kernel = $parsedBootCfg['kernel'];
$modules = $parsedBootCfg['modules'];
// The packaged file may carry its own ks=; we append our own below.
$kernelopt = stripKickstartOption($parsedBootCfg['kernelopt']);

// ---------------------------------------------------------------------------
// Mark the host as deploying and emit the boot script
// ---------------------------------------------------------------------------

$baseUrl = rtrim((string)($globalConfig['webserver']['url'] ?? ''), '/');
if ($baseUrl === '') {
    $webServerIp = $globalConfig['webserver']['ip'] ?? '';
    $baseUrl = 'http://' . $webServerIp;
}
// The version was validated against [A-Za-z0-9._-] above.
$imageUrl = $baseUrl . '/esxi/' . $esxiVersion;
$bootCfgUrl = $baseUrl . '/boot.cfg.php?mac=' . $mac;

// Only the fallback branch below builds a ks= URL of its own; the mboot path
// gets one from boot.cfg.php, which issues its own token. Minted here anyway so
// both branches hand the installer a URL that /ks.cfg will accept -- and the
// later issue simply replaces this one, which is what should happen when a host
// starts over.
$ksUrl = $baseUrl . '/ks.cfg?mac=' . $mac . '&t=' . storeIssueBootToken($mac);

if ($deploymentStatus === 'approved') {
    storeUpdateHost($mac, [
        'deployment_status'  => 'deploying',
        'deployment_started' => date('Y-m-d H:i:s'),
    ]);
}

// The host has a boot script; from here it is fetching a kernel and ~110
// modules over HTTP, which is the longest silent stretch of the install.
storeSetProgress($mac, 10, 'loading the installer');

// mboot.efi is the ESXi bootloader. Where it lives differs between releases
// and between how the media was extracted; bootLoaderResolve() knows the
// layouts ESXi actually ships, and mboot.efi.php asks it the same question.
$loader = bootLoaderResolve($esxiPath);
$mbootUrl = $loader === null ? '' : $imageUrl . $loader;

echo "#!ipxe\n\n";
echo 'echo Booting ESXi ' . sanitizeIpxeText($esxiVersion) . ' installer for '
    . sanitizeIpxeText($hostname) . ' (' . $mac . ")\n\n";

if ($mbootUrl !== '') {
    // Hand control to mboot and let it read the boot.cfg the server renders
    // for this host. mboot is the thing VMware ships to load an ESXi kernel;
    // enumerating ~110 modules into an iPXE script is a re-implementation of
    // what it already does, and one that breaks whenever a release changes
    // its module list. The same file is what a UEFI HTTP Boot host gets, so
    // there is one rewrite to keep correct rather than two.
    echo 'chain ' . $mbootUrl . ' -c ' . $bootCfgUrl . "\n";

    ipxeLog("Chained mboot for $hostname ($mac) using ESXi $esxiVersion");
} else {
    // No loader in the extracted media. Fall back to enumerating the modules,
    // which is what this did before and still works; say so in the log,
    // because the image is not laid out the way it should be.
    ipxeLog(
        "No mboot.efi found under $esxiPath; falling back to enumerating modules for $mac",
        'WARNING'
    );

    echo 'kernel ' . $imageUrl . '/' . ltrim($kernel, '/') . ' ' . $kernelopt . ' ks=' . $ksUrl . "\n";

    foreach ($modules as $module) {
        echo 'module ' . $imageUrl . '/' . ltrim($module, '/') . "\n";
    }

    echo "\nboot\n";

    ipxeLog("Generated iPXE boot script for $hostname ($mac) using ESXi $esxiVersion");
}
