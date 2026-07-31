<?php
/**
 * Progress beacon for a host part way through its installation.
 *
 * Called as /progress.php?mac=<mac>&step=<name> from %firstboot, between the
 * installer finishing and the completion callback. Without it there is a gap
 * of several minutes -- reboot, first boot, esxcli configuration -- where the
 * dashboard has nothing to show and a wedged host looks exactly like a slow
 * one.
 *
 * Unauthenticated, like every other endpoint the boot chain uses: the caller
 * is a freshly installed ESXi host that has no credentials to offer. The step
 * name is matched against a fixed list rather than trusted, so a client cannot
 * write arbitrary text into the operator's console or claim to be finished.
 * Completion stays with deployment_complete.php.
 */

require_once __DIR__ . '/../lib/store.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$mac = formatMac($_GET['mac'] ?? '');
if ($mac === '') {
    http_response_code(400);
    exit("ERROR: missing or malformed MAC address\n");
}

// The token the kickstart carried into %firstboot. Without it any client on the
// provisioning network could drive the progress of any host it could name.
if (!storeVerifyBootToken($mac, (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    logMessage("Progress reported for $mac with a missing or invalid boot token", 'WARNING');
    exit("ERROR: not authorised\n");
}

$step = (string)($_GET['step'] ?? 'firstboot');
$steps = storeProgressSteps();

if (!isset($steps[$step])) {
    http_response_code(400);
    logMessage("Unknown progress step '$step' reported by $mac", 'WARNING');
    exit("ERROR: unknown step\n");
}

[$percentage, $text] = $steps[$step];

if (!storeSetProgress($mac, $percentage, $text)) {
    http_response_code(404);
    logMessage("Progress reported by unregistered MAC: $mac", 'WARNING');
    exit("ERROR: host not found\n");
}

// Report what the host is actually recorded at, not what this step asked for.
// storeSetProgress() never moves a host backwards, so a late beacon from a
// finished install would otherwise be answered with a number that is not true
// -- which is the sort of thing that misleads whoever is reading the logs at
// three in the morning.
$effective = (int)(storeFindHost($mac)['progress'] ?? $percentage);

logMessage("Host $mac reached '$step' ($effective%)", 'INFO', AUTODEPLOY_LOG_DIR . '/deployment.log');

echo "OK $effective\n";
