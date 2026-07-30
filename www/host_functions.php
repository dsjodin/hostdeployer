<?php
/**
 * ESXi Auto-deployment - Host Management Functions
 *
 * Add / edit / approve / delete / reinstall hosts, plus per-host credentials.
 */

require_once __DIR__ . '/../lib/store.php';

/**
 * Persist (or clear) the per-host credential overrides submitted with a form.
 *
 * Both processAddHostAction() and processApproveHostAction() carried an
 * identical ~60 line copy of this logic.
 *
 * @param string $mac      Normalised MAC address
 * @param array  $postData Form data
 * @return bool True when the credentials file was written successfully
 */
function saveHostCredentialOverrides($mac, array $postData) {
    $credentials = storeLoadCredentials();
    if (!is_array($credentials)) {
        $credentials = [];
    }

    foreach (['ilo', 'esxi'] as $type) {
        if (!isset($credentials[$type]) || !is_array($credentials[$type])) {
            $credentials[$type] = [];
        }
        if (!isset($credentials[$type]['hosts']) || !is_array($credentials[$type]['hosts'])) {
            $credentials[$type]['hosts'] = [];
        }
    }

    // iLO override
    $iloUsername = trim((string)($postData['ilo_username'] ?? ''));
    $iloPassword = (string)($postData['ilo_password'] ?? '');

    if (isset($postData['use_custom_ilo']) && ($iloUsername !== '' || $iloPassword !== '')) {
        $entry = $credentials['ilo']['hosts'][$mac] ?? [];
        if ($iloUsername !== '') {
            $entry['username'] = $iloUsername;
        }
        // An empty password field means "keep the stored one", not "erase it";
        // browsers never repopulate password inputs.
        if ($iloPassword !== '') {
            $entry['password'] = $iloPassword;
        }
        $credentials['ilo']['hosts'][$mac] = $entry;
    } elseif (!isset($postData['use_custom_ilo'])) {
        unset($credentials['ilo']['hosts'][$mac]);
    }

    // ESXi override
    $esxiPassword = (string)($postData['esxi_password'] ?? '');

    if (isset($postData['use_custom_esxi'])) {
        if ($esxiPassword !== '') {
            $credentials['esxi']['hosts'][$mac] = ['root_password' => $esxiPassword];
        }
    } else {
        unset($credentials['esxi']['hosts'][$mac]);
    }

    return storeSaveCredentials($credentials);
}

/**
 * Validate the network fields shared by the add and approve forms.
 *
 * @param array $postData Form data
 * @param bool  $requireGateway Whether a gateway is mandatory
 * @return string Empty string when valid, otherwise an error message
 */
function validateHostNetworkInput(array $postData, $requireGateway = true) {
    $managementIp = trim((string)($postData['management_ip'] ?? ''));
    $netmask = trim((string)($postData['management_netmask'] ?? '255.255.255.0'));
    $gateway = trim((string)($postData['management_gateway'] ?? ''));

    if (!isValidIpv4($managementIp)) {
        return 'Invalid ESX management IP address';
    }

    if (!isValidNetmask($netmask)) {
        return 'Invalid ESX management netmask';
    }

    if ($gateway !== '' && !isValidIpv4($gateway)) {
        return 'Invalid ESX management gateway';
    }

    if ($requireGateway && $gateway === '') {
        return 'ESX management gateway is required';
    }

    if (!isValidVlanId($postData['vlan_mgmt'] ?? 0)) {
        return 'Management VLAN must be between 0 and 4094';
    }

    $vmotionIp = trim((string)($postData['vmotion_ip'] ?? ''));
    if ($vmotionIp !== '') {
        if (!isValidIpv4($vmotionIp)) {
            return 'Invalid vMotion IP address';
        }
        $vmotionNetmask = trim((string)($postData['vmotion_netmask'] ?? '255.255.255.0'));
        if (!isValidNetmask($vmotionNetmask)) {
            return 'Invalid vMotion netmask';
        }
        if (!isValidVlanId($postData['vlan_vmotion'] ?? 0)) {
            return 'vMotion VLAN must be between 0 and 4094';
        }
    }

    $iloIp = trim((string)($postData['ilo_ip'] ?? ''));
    if ($iloIp !== '' && !isValidIpv4($iloIp)) {
        return 'Invalid iLO IP address';
    }

    return '';
}

/**
 * Add or update a host from the host editor form.
 *
 * @param array $postData Form data from POST
 * @return array{message: string, error: string}
 */
function processAddHostAction($postData) {
    $result = ['message' => '', 'error' => ''];

    $mac = formatMac($postData['mac'] ?? '');
    if ($mac === '') {
        $result['error'] = 'A valid MAC address is required';
        return $result;
    }

    $fqdn = trim((string)($postData['fqdn'] ?? ''));
    if ($fqdn === '' || !isValidHostname($fqdn)) {
        $result['error'] = 'A valid FQDN is required';
        return $result;
    }

    $hostname = trim((string)($postData['hostname'] ?? ''));
    if ($hostname === '') {
        $hostname = extractHostnameFromFQDN($fqdn);
    }
    if (!isValidHostname($hostname)) {
        $result['error'] = 'Invalid hostname';
        return $result;
    }

    $validationError = validateHostNetworkInput($postData);
    if ($validationError !== '') {
        $result['error'] = $validationError;
        return $result;
    }

    $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
    $deploymentType = ($postData['deployment_type'] ?? 'standard') === 'vcf' ? 'vcf' : 'standard';

    $esxiVersion = (string)($postData['esxi_version'] ?? '');
    $knownVersions = array_keys($globalConfig['deployment']['esxi_versions'] ?? []);
    if ($esxiVersion === '' || !in_array($esxiVersion, $knownVersions, true)) {
        $esxiVersion = $globalConfig['deployment']['default_version'] ?? '';
    }

    $vmotionIp = trim((string)($postData['vmotion_ip'] ?? ''));
    $isStandard = ($deploymentType === 'standard');

    $updatedExisting = false;

    $ok = storeMutateHosts(function (array &$hosts) use (
        $mac, $hostname, $fqdn, $esxiVersion, $deploymentType, $postData, $vmotionIp, $isStandard, &$updatedExisting
    ) {
        $existing = [];
        $existingIndex = -1;
        foreach ($hosts as $index => $host) {
            if (hostMatchesMac($host, $mac)) {
                $existing = $host;
                $existingIndex = $index;
                break;
            }
        }

        // Merge into the existing record so that fields the form does not
        // expose (serial numbers and additional MACs from the iLO scan,
        // deployment history, datastore layout) are no longer wiped out.
        $entry = array_merge($existing, [
            'mac_address'         => $mac,
            'hostname'            => $hostname,
            'fqdn'                => $fqdn,
            'esxi_version'        => $esxiVersion,
            'management_ip'       => trim((string)$postData['management_ip']),
            'management_netmask'  => trim((string)($postData['management_netmask'] ?? '255.255.255.0')),
            'management_gateway'  => trim((string)($postData['management_gateway'] ?? '')),
            'deployment_type'     => $deploymentType,
            'deployment_status'   => 'approved',
            'last_updated'        => date('Y-m-d H:i:s'),
        ]);

        $serial = trim((string)($postData['serial'] ?? ''));
        if ($serial !== '') {
            $entry['serial_number'] = $serial;
        }

        $iloIp = trim((string)($postData['ilo_ip'] ?? ''));
        if ($iloIp !== '') {
            $entry['ilo_ip'] = $iloIp;
        }

        $entry['vlans'] = [
            'management' => (int)($postData['vlan_mgmt'] ?? 0),
            'vmotion'    => $isStandard ? (int)($postData['vlan_vmotion'] ?? 0) : 0,
            'storage'    => (int)($existing['vlans']['storage'] ?? 0),
        ];

        if ($isStandard && $vmotionIp !== '') {
            $entry['vmotion_ip'] = $vmotionIp;
            $entry['vmotion_netmask'] = trim((string)($postData['vmotion_netmask'] ?? '255.255.255.0'));
        } else {
            unset($entry['vmotion_ip'], $entry['vmotion_netmask']);
        }

        if (!isset($entry['datastore'])) {
            $entry['datastore'] = ['name' => 'datastore1', 'drives' => []];
        }
        if (!isset($entry['secure_boot_status'])) {
            $entry['secure_boot_status'] = 'unknown';
        }

        if ($existingIndex >= 0) {
            $hosts[$existingIndex] = $entry;
            $updatedExisting = true;
        } else {
            $hosts[] = $entry;
        }

        return true;
    });

    if (!$ok) {
        $result['error'] = 'Failed to save hosts configuration';
        return $result;
    }

    if (!saveHostCredentialOverrides($mac, $postData)) {
        $result['error'] = 'Host saved, but the credential overrides could not be written';
    }

    logMessage(($updatedExisting ? 'Updated' : 'Added') . " host $hostname ($mac)");
    $result['message'] = "Host '$hostname' " . ($updatedExisting ? 'updated' : 'added') . ' successfully';

    return $result;
}

/**
 * Delete a host.
 *
 * @param array $postData Form data from POST
 * @return array{message: string, error: string}
 */
function processDeleteHostAction($postData) {
    $result = ['message' => '', 'error' => ''];

    $mac = formatMac($postData['mac'] ?? '');
    if ($mac === '') {
        $result['error'] = 'A valid MAC address is required';
        return $result;
    }

    $found = false;
    $ok = storeMutateHosts(function (array &$hosts) use ($mac, &$found) {
        foreach ($hosts as $index => $host) {
            if (hostMatchesMac($host, $mac)) {
                array_splice($hosts, $index, 1);
                $found = true;
                break;
            }
        }
        return $found;
    });

    if (!$found) {
        $result['error'] = "Host with MAC '$mac' not found";
        return $result;
    }

    if (!$ok) {
        $result['error'] = 'Failed to save hosts configuration after deletion';
        return $result;
    }

    // Drop any credential overrides so they cannot leak to a future host
    // that happens to reuse the MAC.
    $credentials = storeLoadCredentials();
    if (is_array($credentials)) {
        unset($credentials['ilo']['hosts'][$mac], $credentials['esxi']['hosts'][$mac]);
        storeSaveCredentials($credentials);
    }

    logMessage("Deleted host $mac");
    $result['message'] = "Host with MAC '$mac' deleted successfully";

    return $result;
}

/**
 * Enable or disable secure boot for a host.
 *
 * @param array $postData Form data from POST
 * @return array{message: string, error: string, scanOutput: string}
 */
function processSecureBootAction($postData) {
    $result = ['message' => '', 'error' => '', 'scanOutput' => ''];

    $mac = formatMac($postData['mac'] ?? '');
    if ($mac === '') {
        $result['error'] = 'A valid MAC address is required';
        return $result;
    }

    $enable = (($postData['secure_boot'] ?? '') === 'enable');

    $toggleResult = toggleSecureBoot($mac, $enable);

    if (!$toggleResult['success']) {
        $result['error'] = 'Failed to ' . ($enable ? 'enable' : 'disable') . ' secure boot';
        $result['scanOutput'] = $toggleResult['output'];
        return $result;
    }

    // secure_boot_manager.py already records the new status; refresh it here
    // too so the dashboard is correct even if the script's write raced.
    storeUpdateHost($mac, ['secure_boot_status' => $enable ? 'enabled' : 'disabled']);

    $result['message'] = 'Secure boot ' . ($enable ? 'enabled' : 'disabled') . " for host with MAC '$mac'";

    return $result;
}

/**
 * Approve a pending host for deployment.
 *
 * @param array $postData Form data from POST
 * @return array{message: string, error: string}
 */
function processApproveHostAction($postData) {
    $result = ['message' => '', 'error' => ''];

    $mac = formatMac($postData['mac'] ?? '');
    if ($mac === '') {
        $result['error'] = 'A valid MAC address is required';
        return $result;
    }

    $hostname = trim((string)($postData['hostname'] ?? ''));
    if ($hostname === '' || !isValidHostname($hostname)) {
        $result['error'] = 'A valid hostname is required to approve a host';
        return $result;
    }

    $validationError = validateHostNetworkInput($postData);
    if ($validationError !== '') {
        $result['error'] = $validationError;
        return $result;
    }

    $fqdn = trim((string)($postData['fqdn'] ?? ''));
    if ($fqdn === '') {
        $fqdn = $hostname . '.local';
    }
    if (!isValidHostname($fqdn)) {
        $result['error'] = 'Invalid FQDN';
        return $result;
    }

    $deploymentType = ($postData['deployment_type'] ?? 'standard') === 'vcf' ? 'vcf' : 'standard';
    $vmotionIp = trim((string)($postData['vmotion_ip'] ?? ''));

    $found = false;
    $ok = storeMutateHosts(function (array &$hosts) use (
        $mac, $hostname, $fqdn, $deploymentType, $postData, $vmotionIp, &$found
    ) {
        foreach ($hosts as &$host) {
            if (!hostMatchesMac($host, $mac)) {
                continue;
            }

            $host['hostname'] = $hostname;
            $host['fqdn'] = $fqdn;
            $host['management_ip'] = trim((string)$postData['management_ip']);
            $host['management_netmask'] = trim((string)($postData['management_netmask'] ?? '255.255.255.0'));
            $host['management_gateway'] = trim((string)($postData['management_gateway'] ?? ''));
            $host['deployment_type'] = $deploymentType;

            if (!isset($host['vlans']) || !is_array($host['vlans'])) {
                $host['vlans'] = ['management' => 0, 'vmotion' => 0, 'storage' => 0];
            }
            $host['vlans']['management'] = (int)($postData['vlan_mgmt'] ?? 0);

            if ($deploymentType === 'standard' && $vmotionIp !== '') {
                $host['vmotion_ip'] = $vmotionIp;
                $host['vmotion_netmask'] = trim((string)($postData['vmotion_netmask'] ?? '255.255.255.0'));
                $host['vlans']['vmotion'] = (int)($postData['vlan_vmotion'] ?? 0);
            } else {
                unset($host['vmotion_ip'], $host['vmotion_netmask']);
                $host['vlans']['vmotion'] = 0;
            }

            $host['deployment_status'] = 'approved';
            $host['approved_time'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
        unset($host);

        return $found;
    });

    if (!$found) {
        $result['error'] = "Host with MAC '$mac' not found";
        return $result;
    }

    if (!$ok) {
        $result['error'] = 'Failed to update host approval status';
        return $result;
    }

    if (!saveHostCredentialOverrides($mac, $postData)) {
        $result['error'] = 'Host approved, but the credential overrides could not be written';
    }

    logMessage("Approved host $hostname ($mac) for deployment");
    $result['message'] = "Host with MAC '$mac' approved for deployment";

    return $result;
}

/**
 * Mark a deployed host for reinstallation.
 *
 * This handler was wired up in the hosts tab but never implemented, so the
 * "Reinstall" button produced a fatal "undefined function" error.
 *
 * @param array $postData Form data from POST
 * @return array{message: string, error: string}
 */
function processReinstallHostAction($postData) {
    $result = ['message' => '', 'error' => ''];

    $mac = formatMac($postData['mac'] ?? '');
    if ($mac === '') {
        $result['error'] = 'A valid MAC address is required';
        return $result;
    }

    $found = false;
    $ok = storeMutateHosts(function (array &$hosts) use ($mac, &$found) {
        foreach ($hosts as &$host) {
            if (!hostMatchesMac($host, $mac)) {
                continue;
            }
            $host['deployment_status'] = 'approved';
            $host['approved_time'] = date('Y-m-d H:i:s');
            $host['reinstall_requested'] = date('Y-m-d H:i:s');
            unset($host['deployment_started'], $host['deployment_time']);
            $found = true;
            break;
        }
        unset($host);

        return $found;
    });

    if (!$found) {
        $result['error'] = "Host with MAC '$mac' not found";
        return $result;
    }

    if (!$ok) {
        $result['error'] = 'Failed to mark host for reinstallation';
        return $result;
    }

    logMessage("Host $mac marked for reinstallation");
    $result['message'] = "Host '$mac' is queued for reinstallation. Reboot it to start the network install.";

    return $result;
}
