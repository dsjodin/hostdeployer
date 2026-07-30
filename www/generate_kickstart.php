<?php
/**
 * Dynamic kickstart generator for ESXi.
 *
 * Served as /ks.cfg?mac=<mac>. Picks a template based on the host's
 * deployment type and fills in per-host network / credential values.
 */

require_once __DIR__ . '/../lib/store.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

const KICKSTART_LOG = AUTODEPLOY_LOG_DIR . '/kickstart_generator.log';

/**
 * @param string $message Message
 * @param string $level   Level
 */
function ksLog($message, $level = 'INFO') {
    logMessage($message, $level, KICKSTART_LOG);
}

/**
 * Emit a kickstart that stops the installation, and exit.
 *
 * @param string   $reason  Human-readable reason
 * @param string[] $details Extra comment lines
 * @param int      $status  HTTP status code
 */
function ksAbort($reason, array $details = [], $status = 200) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# $reason\n";
    foreach ($details as $detail) {
        echo '# ' . str_replace(["\r", "\n"], ' ', $detail) . "\n";
    }
    // Without an install directive weasel simply stops; rebooting here would
    // put the host into a PXE loop.
    echo "%pre --interpreter=busybox\n";
    echo "echo '$reason'\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    ksLog('Kickstart generator started');

    $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
    $hostsConfig  = storeLoadHostsConfig();

    if ($globalConfig === null || $hostsConfig === null) {
        ksLog('Configuration loading failed', 'ERROR');
        ksAbort('Deployment server configuration is unavailable', [], 500);
    }

    $clientMac = formatMac($_GET['mac'] ?? '');
    if ($clientMac === '') {
        // Fall back to header/ARP discovery when the bootloader dropped the
        // query string.
        $clientMac = getClientMacAddress() ?? '';
    }

    if ($clientMac === '') {
        ksLog('Missing or malformed MAC address parameter', 'ERROR');
        ksAbort('Missing MAC address parameter', [], 400);
    }

    ksLog("Kickstart requested for MAC: $clientMac");

    $hostConfig = findHostByMac($clientMac, $hostsConfig);
    $status = $hostConfig['deployment_status'] ?? 'unknown';

    if ($hostConfig === null) {
        ksLog("Kickstart requested by unregistered MAC: $clientMac", 'WARNING');
        ksAbort('This server is not registered for deployment', ["MAC: $clientMac"]);
    }

    // Hosts awaiting approval get the waiting template so the installer idles
    // instead of erroring out. This branch used to be unreachable because the
    // status check above rejected everything that was not approved/deploying.
    if ($status === 'pending' || $status === 'unknown') {
        ksLog("Host $clientMac is pending approval");

        $waitingTemplate = $globalConfig['deployment']['waiting_template_path'] ?? '';
        if ($waitingTemplate === '' || !is_file($waitingTemplate)) {
            ksLog("Waiting template not found: $waitingTemplate", 'ERROR');
            ksAbort('This server is awaiting approval', ["MAC: $clientMac"]);
        }

        $template = (string)file_get_contents($waitingTemplate);
        echo renderTemplate($template, [
            'MAC_ADDRESS'     => $clientMac,
            'REGISTERED_TIME' => $hostConfig['registered_time'] ?? date('Y-m-d H:i:s'),
            'SERVER_IP'       => $globalConfig['webserver']['ip'] ?? '',
        ]);
        exit;
    }

    if ($status !== 'approved' && $status !== 'deploying') {
        ksLog("Kickstart denied for $clientMac, status: $status", 'WARNING');
        ksAbort('This server is not approved for deployment', ["MAC: $clientMac", "Status: $status"]);
    }

    // The installer may report the chassis serial; record it opportunistically.
    storeTouchHost($clientMac, $_GET['serial'] ?? null);

    // Secure boot has to be off for the unsigned installer chain to load.
    if (!empty($globalConfig['security']['secure_boot_enabled'])
        && ($hostConfig['secure_boot_status'] ?? '') !== 'disabled') {
        disableSecureBoot($clientMac);
    }

    $deploymentType = $hostConfig['deployment_type']
        ?? $globalConfig['deployment']['default_deployment_type']
        ?? 'standard';

    $templates = $globalConfig['deployment']['kickstart_templates'] ?? [];
    $templatePath = $templates[$deploymentType] ?? null;

    if ($templatePath === null) {
        $templatePath = $templates['standard'] ?? (AUTODEPLOY_ROOT . '/templates/kickstart_template_std.cfg');
        ksLog("No template configured for deployment type '$deploymentType', using standard", 'WARNING');
    }

    if (!is_file($templatePath)) {
        ksLog("Kickstart template not found: $templatePath", 'ERROR');
        ksAbort('Kickstart template is missing on the deployment server', [], 500);
    }

    $template = file_get_contents($templatePath);
    if ($template === false) {
        ksLog("Failed to read kickstart template: $templatePath", 'ERROR');
        ksAbort('Could not read the kickstart template', [], 500);
    }

    // Root password: host-specific override wins, then the credentials file,
    // then the (deprecated) plaintext value in global_config.json.
    $esxiCredentials = storeLoadCredentials('esxi', $clientMac);
    $rootPassword = $esxiCredentials['root_password']
        ?? $globalConfig['deployment']['esxi_root_password']
        ?? '';

    if ($rootPassword === '') {
        ksLog('No ESXi root password configured', 'ERROR');
        ksAbort('No ESXi root password is configured on the deployment server', [], 500);
    }

    $dnsServers = implode(',', (array)($globalConfig['network']['dns_servers'] ?? []));
    $ntpServers = implode(',', (array)($globalConfig['network']['ntp_servers'] ?? []));
    $serverIp = $globalConfig['webserver']['ip'] ?? '';

    $variables = [
        'ROOT_PASSWORD_HASH' => generateEsxiPasswordHash($rootPassword),
        'ESXMGMT_IP'         => $hostConfig['management_ip'] ?? '',
        'ESXMGMT_NETMASK'    => $hostConfig['management_netmask'] ?? '255.255.255.0',
        'ESXMGMT_GATEWAY'    => $hostConfig['management_gateway'] ?? '',
        'ESXIMGMT_VLANID'    => (int)($hostConfig['vlans']['management'] ?? 0),
        'DNS_SERVERS'        => $dnsServers,
        'NTP_SERVERS'        => $ntpServers,
        'HOSTNAME'           => $hostConfig['hostname'] ?? '',
        'FQDN'               => $hostConfig['fqdn'] ?: (($hostConfig['hostname'] ?? 'esxi') . '.local'),
        'SERVER_IP'          => $serverIp,
        'SERVER_URL'         => rtrim((string)($globalConfig['webserver']['url'] ?? "http://$serverIp"), '/'),
        'MAC_ADDRESS'        => $clientMac,
        'DATASTORE_NAME'     => $hostConfig['datastore']['name'] ?? 'datastore1',
    ];

    // vMotion is only rendered when the host actually has an address for it.
    $vmotionIp = $hostConfig['vmotion_ip'] ?? '';
    if ($deploymentType === 'standard' && $vmotionIp !== '') {
        $variables['VMOTION_CONFIGURED'] = true;
        $variables['VMOTION_IP'] = $vmotionIp;
        $variables['VMOTION_NETMASK'] = $hostConfig['vmotion_netmask'] ?? '255.255.255.0';
        $variables['VMOTION_VLANID'] = (int)($hostConfig['vlans']['vmotion'] ?? 0);
    } else {
        $variables['VMOTION_CONFIGURED'] = false;
    }

    $kickstart = renderTemplate($template, $variables);

    if ($status === 'approved') {
        storeUpdateHost($clientMac, [
            'deployment_status'  => 'deploying',
            'deployment_started' => date('Y-m-d H:i:s'),
        ]);
    }

    // The installer reached us, so the kernel and every module loaded.
    storeSetProgress($clientMac, 50, 'installing');

    echo $kickstart;

    ksLog("Generated $deploymentType kickstart for $clientMac ({$variables['HOSTNAME']})");
} catch (Throwable $e) {
    ksLog('Exception: ' . $e->getMessage(), 'ERROR');
    ksAbort('Internal server error while generating the kickstart file', [], 500);
}
