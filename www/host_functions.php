<?php
/**
 * ESXi Auto-deployment - Host Management Functions
 * 
 * Functions for managing hosts, including adding, editing, and deleting hosts
 */

/**
* Process add/edit host action with vMotion support and custom credentials
* 
* @param array $postData Form data from POST
* @return array Result with message/error information
*/
function processAddHostAction($postData) {
   $result = [
       'message' => '',
       'error' => ''
   ];
   
   $hostsConfigPath = '/srv/autodeploy/config/hosts.json';
   $globalConfigPath = '/srv/autodeploy/config/global_config.json';
   
   $hostsConfig = loadJsonConfig($hostsConfigPath);
   $globalConfig = loadJsonConfig($globalConfigPath);
   
   if (!$hostsConfig) {
       $result['error'] = "Cannot add host - hosts configuration file not found";
       return $result;
   }
   
   $mac = formatMac($postData['mac']);
   $fqdn = $postData['fqdn'];
   $hostname = $postData['hostname'] ?: extractHostnameFromFQDN($fqdn);
   $managementIp = $postData['management_ip'];
   $managementNetmask = $postData['management_netmask'];
   $managementGateway = $postData['management_gateway'];
   $deploymentType = $postData['deployment_type'] ?? 'standard';
   
   // Add vMotion fields
   $vmotionIp = $postData['vmotion_ip'] ?? '';
   $vmotionNetmask = $postData['vmotion_netmask'] ?? '255.255.255.0';
   
   // Validate inputs
   if (empty($mac) || empty($fqdn) || empty($managementIp)) {
       $result['error'] = "MAC address, FQDN, and ESX management IP are required";
       return $result;
   }
   
   if (!isValidIp($managementIp)) {
       $result['error'] = "Invalid ESX management IP address";
       return $result;
   }
   
   // Validate vMotion IP if provided
   if (!empty($vmotionIp) && !isValidIp($vmotionIp)) {
       $result['error'] = "Invalid vMotion IP address";
       return $result;
   }
   
   // Check if host already exists
   $existingHost = null;
   $existingIndex = -1;
   
   foreach ($hostsConfig['hosts'] as $index => $host) {
       if (formatMac($host['mac_address']) === $mac) {
           $existingHost = $host;
           $existingIndex = $index;
           break;
       }
   }
   
   // Set up VLANs based on deployment type
   $vlans = [
       'management' => (int)($postData['vlan_mgmt'] ?? 0),
       'vmotion' => 0,
       'storage' => 0
   ];
   
   // Only set vMotion VLAN for standard deployments
   if ($deploymentType === 'standard') {
       $vlans['vmotion'] = (int)($postData['vlan_vmotion'] ?? 0);
   }
   
   // Create or update host entry
   $hostEntry = [
       'mac_address' => $mac,
       'hostname' => $hostname,
       'esxi_version' => $postData['esxi_version'] ?? $globalConfig['deployment']['default_version'],
       'fqdn' => $fqdn,
       'serial_number' => $postData['serial'] ?: null,
       'ilo_ip' => $postData['ilo_ip'] ?: null,
       'management_ip' => $managementIp,
       'management_netmask' => $managementNetmask,
       'management_gateway' => $managementGateway,
       'vlans' => $vlans,
       'vmotion_ip' => $vmotionIp,
       'vmotion_netmask' => $vmotionNetmask,
       'datastore' => [
           'name' => 'datastore1',
           'drives' => []
       ],
       'deployment_type' => $deploymentType,
       'secure_boot_status' => $existingHost['secure_boot_status'] ?? 'unknown',
       'deployment_status' => 'approved',
       'last_updated' => date('Y-m-d H:i:s')
   ];
   
   // Add or update the host
   if ($existingIndex >= 0) {
       $hostsConfig['hosts'][$existingIndex] = $hostEntry;
       $result['message'] = "Host '$hostname' updated successfully";
   } else {
       $hostsConfig['hosts'][] = $hostEntry;
       $result['message'] = "Host '$hostname' added successfully";
   }
   
   // Save the updated hosts configuration
   if (!saveJsonConfig($hostsConfigPath, $hostsConfig)) {
       $result['error'] = "Failed to save hosts configuration";
       $result['message'] = '';
       return $result;
   }
   
   // Handle custom credentials if enabled
   // Load existing credentials
   $credentials = loadSecureCredentials();
   if (!$credentials) {
       $credentials = [
           'ilo' => ['hosts' => []],
           'esxi' => ['hosts' => []]
       ];
   }
   
   // Ensure structure exists
   if (!isset($credentials['ilo']['hosts'])) {
       $credentials['ilo']['hosts'] = [];
   }
   
   if (!isset($credentials['esxi']['hosts'])) {
       $credentials['esxi']['hosts'] = [];
   }
   
   // Process iLO credentials
   if (isset($postData['use_custom_ilo'])) {
       $iloUsername = $postData['ilo_username'] ?? '';
       $iloPassword = $postData['ilo_password'] ?? '';
       
       if (!empty($iloUsername) || !empty($iloPassword)) {
           $credentials['ilo']['hosts'][$mac] = [
               'username' => $iloUsername,
               'password' => $iloPassword
           ];
       } else {
           // Remove custom credentials if empty
           if (isset($credentials['ilo']['hosts'][$mac])) {
               unset($credentials['ilo']['hosts'][$mac]);
           }
       }
   } else {
       // Remove any existing custom credentials
       if (isset($credentials['ilo']['hosts'][$mac])) {
           unset($credentials['ilo']['hosts'][$mac]);
       }
   }
   
   // Process ESXi credentials
   if (isset($postData['use_custom_esxi'])) {
       $esxiPassword = $postData['esxi_password'] ?? '';
       
       if (!empty($esxiPassword)) {
           $credentials['esxi']['hosts'][$mac] = [
               'root_password' => $esxiPassword
           ];
       } else {
           // Remove custom credentials if empty
           if (isset($credentials['esxi']['hosts'][$mac])) {
               unset($credentials['esxi']['hosts'][$mac]);
           }
       }
   } else {
       // Remove any existing custom credentials
       if (isset($credentials['esxi']['hosts'][$mac])) {
           unset($credentials['esxi']['hosts'][$mac]);
       }
   }
   
   // Save updated credentials
   $credentialsPath = '/srv/autodeploy/config/credentials.json';
   file_put_contents($credentialsPath, json_encode($credentials, JSON_PRETTY_PRINT));
   
   return $result;
}

/**
 * Process delete host action
 * 
 * @param array $postData Form data from POST
 * @return array Result with message/error information
 */
function processDeleteHostAction($postData) {
    $result = [
        'message' => '',
        'error' => ''
    ];
    
    $hostsConfigPath = '/srv/autodeploy/config/hosts.json';
    $hostsConfig = loadJsonConfig($hostsConfigPath);
    
    if (!$hostsConfig) {
        $result['error'] = "Cannot delete host - hosts configuration file not found";
        return $result;
    }
    
    $mac = $postData['mac'];
    $found = false;
    
    foreach ($hostsConfig['hosts'] as $index => $host) {
        if (formatMac($host['mac_address']) === formatMac($mac)) {
            array_splice($hostsConfig['hosts'], $index, 1);
            $found = true;
            break;
        }
    }
    
    if ($found) {
        if (saveJsonConfig($hostsConfigPath, $hostsConfig)) {
            $result['message'] = "Host with MAC '$mac' deleted successfully";
        } else {
            $result['error'] = "Failed to save hosts configuration after deletion";
        }
    } else {
        $result['error'] = "Host with MAC '$mac' not found";
    }
    
    return $result;
}

/**
 * Process secure boot toggle action
 * 
 * @param array $postData Form data from POST
 * @return array Result with message/error/scanOutput information
 */
function processSecureBootAction($postData) {
    $result = [
        'message' => '',
        'error' => '',
        'scanOutput' => ''
    ];
    
    $hostsConfigPath = '/srv/autodeploy/config/hosts.json';
    $hostsConfig = loadJsonConfig($hostsConfigPath);
    
    if (!$hostsConfig) {
        $result['error'] = "Cannot manage secure boot - hosts configuration file not found";
        return $result;
    }
    
    $mac = $postData['mac'];
    $enable = ($postData['secure_boot'] === 'enable');
    
    $toggleResult = toggleSecureBoot($mac, $enable);
    
    if ($toggleResult['success']) {
        // Update host status in configuration
        foreach ($hostsConfig['hosts'] as &$host) {
            if (formatMac($host['mac_address']) === formatMac($mac)) {
                $host['secure_boot_status'] = $enable ? 'enabled' : 'disabled';
                break;
            }
        }
        
        saveJsonConfig($hostsConfigPath, $hostsConfig);
        
        $result['message'] = "Secure boot " . ($enable ? "enabled" : "disabled") . " for host with MAC '$mac'";
    } else {
        $result['error'] = "Failed to " . ($enable ? "enable" : "disable") . " secure boot";
        $result['scanOutput'] = $toggleResult['output'];
    }
    
    return $result;
}

/**
* Process approve host action with vMotion and custom credentials support
* 
* @param array $postData Form data from POST
* @return array Result with message/error information
*/
function processApproveHostAction($postData) {
   $result = [
       'message' => '',
       'error' => ''
   ];
   
   $hostsConfigPath = '/srv/autodeploy/config/hosts.json';
   $hostsConfig = loadJsonConfig($hostsConfigPath);
   
   if (!$hostsConfig) {
       $result['error'] = "Cannot approve host - hosts configuration file not found";
       return $result;
   }
   
   $mac = $postData['mac'];
   $hostname = $postData['hostname'] ?? '';
   $managementIp = $postData['management_ip'] ?? '';
   
   if (empty($hostname) || empty($managementIp)) {
       $result['error'] = "Hostname and ESX management IP are required to approve a host";
       return $result;
   }
   
   $deploymentType = $postData['deployment_type'] ?? 'standard';
   
   $updated = false;
   foreach ($hostsConfig['hosts'] as &$host) {
       if (formatMac($host['mac_address']) === formatMac($mac)) {
           $host['hostname'] = $hostname;
           $host['management_ip'] = $managementIp;
           $host['management_netmask'] = $postData['management_netmask'] ?? '255.255.255.0';
           $host['management_gateway'] = $postData['management_gateway'] ?? '';
           $host['fqdn'] = $postData['fqdn'] ?? "$hostname.local";
           $host['deployment_type'] = $deploymentType;
           
           // Set VLANs
           if (!isset($host['vlans'])) {
               $host['vlans'] = ['management' => 0, 'vmotion' => 0, 'storage' => 0];
           }
           $host['vlans']['management'] = (int)($postData['vlan_mgmt'] ?? 0);
           
           // Add vMotion fields if provided and deployment type is standard
           if ($deploymentType === 'standard') {
               if (isset($postData['vmotion_ip']) && !empty($postData['vmotion_ip'])) {
                   $host['vmotion_ip'] = $postData['vmotion_ip'];
                   $host['vmotion_netmask'] = $postData['vmotion_netmask'] ?? '255.255.255.0';
                   $host['vlans']['vmotion'] = (int)($postData['vlan_vmotion'] ?? 0);
               }
           } else {
               // For VCF deployments, clear vMotion config
               unset($host['vmotion_ip']);
               unset($host['vmotion_netmask']);
               $host['vlans']['vmotion'] = 0;
           }
           
           $host['deployment_status'] = 'approved';
           $host['approved_time'] = date('Y-m-d H:i:s');
           $updated = true;
           break;
       }
   }
   
   if ($updated) {
       if (!saveJsonConfig($hostsConfigPath, $hostsConfig)) {
           $result['error'] = "Failed to update host approval status";
           return $result;
       }
   } else {
       $result['error'] = "Host with MAC '$mac' not found";
       return $result;
   }

   // Handle custom credentials if enabled
   $mac = formatMac($mac);
   
   // Load existing credentials
   $credentials = loadSecureCredentials();
   if (!$credentials) {
       $credentials = [
           'ilo' => ['hosts' => []],
           'esxi' => ['hosts' => []]
       ];
   }
   
   // Ensure structure exists
   if (!isset($credentials['ilo']['hosts'])) {
       $credentials['ilo']['hosts'] = [];
   }
   
   if (!isset($credentials['esxi']['hosts'])) {
       $credentials['esxi']['hosts'] = [];
   }
   
   // Process iLO credentials
   if (isset($postData['use_custom_ilo'])) {
       $iloUsername = $postData['ilo_username'] ?? '';
       $iloPassword = $postData['ilo_password'] ?? '';
       
       if (!empty($iloUsername) || !empty($iloPassword)) {
           $credentials['ilo']['hosts'][$mac] = [
               'username' => $iloUsername,
               'password' => $iloPassword
           ];
       } else {
           // Remove custom credentials if empty
           if (isset($credentials['ilo']['hosts'][$mac])) {
               unset($credentials['ilo']['hosts'][$mac]);
           }
       }
   } else {
       // Remove any existing custom credentials
       if (isset($credentials['ilo']['hosts'][$mac])) {
           unset($credentials['ilo']['hosts'][$mac]);
       }
   }
   
   // Process ESXi credentials
   if (isset($postData['use_custom_esxi'])) {
       $esxiPassword = $postData['esxi_password'] ?? '';
       
       if (!empty($esxiPassword)) {
           $credentials['esxi']['hosts'][$mac] = [
               'root_password' => $esxiPassword
           ];
       } else {
           // Remove custom credentials if empty
           if (isset($credentials['esxi']['hosts'][$mac])) {
               unset($credentials['esxi']['hosts'][$mac]);
           }
       }
   } else {
       // Remove any existing custom credentials
       if (isset($credentials['esxi']['hosts'][$mac])) {
           unset($credentials['esxi']['hosts'][$mac]);
       }
   }
   
   // Save updated credentials
   $credentialsPath = '/srv/autodeploy/config/credentials.json';
   file_put_contents($credentialsPath, json_encode($credentials, JSON_PRETTY_PRINT));
   
   $result['message'] = "Host with MAC '$mac' approved for deployment";
   return $result;
}