<?php
/**
 * ESXi Auto-deployment Admin - Hosts Tab
 *
 * Note: this file must not enable display_errors. It used to, which meant
 * including it silently switched the whole dashboard into a mode where PHP
 * warnings and stack traces were rendered into the page.
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * Render the Hosts Management Tab
 * 
 * @param array $globalConfig Global configuration
 * @param array $pendingHosts List of pending hosts
 * @param array $approvedHosts List of approved hosts
 * @param array $deployingHosts List of deploying hosts
 * @param array $deployedHosts List of deployed hosts
 */
function renderHostsContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts) {
    ?>
    <div class="row">
        <div class="col-12 mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 text-gray-800">Host Management</h1>
                <button class="btn btn-success" id="toggle-host-form">
                    <i class="fas fa-plus-circle"></i> Add New Host
                </button>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Host Form Card -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4" id="add-host-card" style="display: none;">
                <div class="card-header">
                    <h5 class="m-0 font-weight-bold text-primary" id="host-form-title">Add New Host</h5>
                </div>
                <div class="card-body">
                    <form id="add-host-form" method="post" action="" class="needs-validation" novalidate>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add_host">
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="mac" class="form-label required">MAC Address:</label>
                                <input type="text" class="form-control" id="mac" name="mac" required placeholder="00:11:22:33:44:55">
                                <div class="invalid-feedback">Please provide a valid MAC address.</div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="esxi_version" class="form-label">ESXi Version:</label>
                                <select class="form-select" id="esxi_version" name="esxi_version">
                                    <?php 
                                    // Get the default version
                                    $defaultVersion = $globalConfig['deployment']['default_version'] ?? '';
                                    
                                    // Get all available versions from global config
                                    if (isset($globalConfig['deployment']['esxi_versions']) && is_array($globalConfig['deployment']['esxi_versions'])) {
                                        foreach ($globalConfig['deployment']['esxi_versions'] as $version => $versionConfig) {
                                            $selected = ($version === $defaultVersion) ? 'selected' : '';
                                            echo "<option value=\"$version\" $selected>ESXi $version</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="deployment_type" class="form-label">Deployment Type:</label>
                                <select class="form-select" id="deployment_type" name="deployment_type">
                                    <option value="standard">Standard ESXi</option>
                                    <option value="vcf">VMware Cloud Foundation (VCF)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fqdn" class="form-label required">FQDN:</label>
                                <input type="text" class="form-control" id="fqdn" name="fqdn" required placeholder="esxi01.example.com">
                                <input type="hidden" id="hostname" name="hostname">
                                <div class="invalid-feedback">Please provide a valid FQDN.</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="serial" class="form-label">Serial Number:</label>
                                <input type="text" class="form-control" id="serial" name="serial" placeholder="ABCD123456">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="ilo_ip" class="form-label">iLO Address:</label>
                                <input type="text" class="form-control" id="ilo_ip" name="ilo_ip"
                                       placeholder="orbesx1001-ilo.dc.infra">
                            </div>
                        </div>
                        
                        <h5 class="mt-4 mb-3 border-bottom pb-2">Network Configuration</h5>
                        
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="vlan_mgmt" class="form-label">ESX Management VLAN:</label>
                                <input type="number" class="form-control" id="vlan_mgmt" name="vlan_mgmt" min="0" max="4094" value="0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="management_ip" class="form-label required">ESX Management IP:</label>
                                <input type="text" class="form-control" id="management_ip" name="management_ip" required placeholder="192.168.1.10">
                                <div class="invalid-feedback">Please provide a management IP address.</div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="management_netmask" class="form-label">ESX Management Netmask:</label>
                                <input type="text" class="form-control" id="management_netmask" name="management_netmask" value="255.255.255.0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="management_gateway" class="form-label required">ESX Management Gateway:</label>
                                <input type="text" class="form-control" id="management_gateway" name="management_gateway" required placeholder="192.168.1.1">
                                <div class="invalid-feedback">Please provide a gateway address.</div>
                            </div>
                        </div>
                        
                        <div id="vmotion_config_section">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="vlan_vmotion" class="form-label">vMotion VLAN:</label>
                                    <input type="number" class="form-control" id="vlan_vmotion" name="vlan_vmotion" min="0" max="4094" value="0">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="vmotion_ip" class="form-label">vMotion IP:</label>
                                    <input type="text" class="form-control" id="vmotion_ip" name="vmotion_ip" placeholder="192.168.2.10">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="vmotion_netmask" class="form-label">vMotion Netmask:</label>
                                    <input type="text" class="form-control" id="vmotion_netmask" name="vmotion_netmask" value="255.255.255.0">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mt-4 mb-3 border-bottom pb-2">Credentials</h5>

                        <div class="row">
                            <div class="col-md-12 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="use_custom_ilo" name="use_custom_ilo" 
                                        onchange="toggleIloCredFields(this.checked)">
                                    <label class="form-check-label" for="use_custom_ilo">
                                        Use custom iLO credentials
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="ilo_credentials_fields" style="display: none;">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="ilo_username" class="form-label">iLO Username:</label>
                                    <input type="text" class="form-control" id="ilo_username" name="ilo_username" 
                                        placeholder="Default: Administrator">
                                    <div class="form-text">Leave blank to use global default username</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="ilo_password" class="form-label">iLO Password:</label>
                                    <input type="password" class="form-control" id="ilo_password" name="ilo_password">
                                    <div class="form-text">Leave blank to use global default password</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="use_custom_esxi" name="use_custom_esxi" 
                                        onchange="toggleEsxiCredFields(this.checked)">
                                    <label class="form-check-label" for="use_custom_esxi">
                                        Use custom ESXi root password
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="esxi_credentials_fields" style="display: none;">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="esxi_password" class="form-label">ESXi Root Password:</label>
                                    <input type="password" class="form-control" id="esxi_password" name="esxi_password">
                                    <div class="form-text">Leave blank to use global default password</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end mt-3">
                            <button type="button" class="btn btn-secondary me-2" id="cancel-host-form">Cancel</button>
                            <button type="submit" class="btn btn-success">Save Host</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Host Management Tabs -->
    <div class="row">
        <div class="col-12">
            <!-- Replace the existing tab structure in hosts.php with this HTML structure -->
<div class="card shadow mb-4">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="hostsTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" 
                        type="button" role="tab" aria-controls="approved" aria-selected="true">
                    Approved <span class="badge bg-primary"><?php echo count($approvedHosts); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" 
                        type="button" role="tab" aria-controls="pending" aria-selected="false">
                    Pending <span class="badge bg-warning text-dark"><?php echo count($pendingHosts); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="deployed-tab" data-bs-toggle="tab" data-bs-target="#deployed" 
                        type="button" role="tab" aria-controls="deployed" aria-selected="false">
                    Deploying/Deployed <span class="badge bg-success"><?php echo count($deployingHosts) + count($deployedHosts); ?></span>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content" id="hostsTabContent">
            <!-- Approved Hosts Tab Content -->
            <div class="tab-pane fade show active" id="approved" role="tabpanel" aria-labelledby="approved-tab">
                <!-- Approved hosts content here -->
                <?php if (empty($approvedHosts)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No approved hosts found.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Hostname</th>
                                <th>MAC Address</th>
                                <th>ESX Management IP</th>
                                <th>Secure Boot</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedHosts as $host): ?>
                            <tr>
                                <td><?php echo h($host['hostname']); ?></td>
                                <td><?php echo h(formatMac($host['mac_address'])); ?></td>
                                <td><?php echo h($host['management_ip']); ?></td>
                                <td>
                                    <?php if (($host['secure_boot_status'] ?? '') === 'enabled'): ?>
                                        <span class="badge bg-success">Enabled</span>
                                    <?php elseif (($host['secure_boot_status'] ?? '') === 'disabled'): ?>
                                        <span class="badge bg-warning text-dark">Disabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-primary" data-edit-host="<?php echo jsValue([
                                            'mac'             => formatMac($host['mac_address']),
                                            'fqdn'            => $host['fqdn'] ?: (($host['hostname'] ?? 'esxi') . '.local'),
                                            'serial'          => $host['serial_number'] ?? '',
                                            'iloIp'           => $host['ilo_ip'] ?? '',
                                            'mgmtIp'          => $host['management_ip'] ?? '',
                                            'mgmtNetmask'     => $host['management_netmask'] ?? '255.255.255.0',
                                            'mgmtGateway'     => $host['management_gateway'] ?? '',
                                            'vlanMgmt'        => (int)($host['vlans']['management'] ?? 0),
                                            'vlanVmotion'     => (int)($host['vlans']['vmotion'] ?? 0),
                                            'vmotionIp'       => $host['vmotion_ip'] ?? '',
                                            'vmotionNetmask'  => $host['vmotion_netmask'] ?? '255.255.255.0',
                                            'deploymentType'  => $host['deployment_type'] ?? 'standard',
                                            'esxiVersion'     => $host['esxi_version'] ?? '',
                                        ]); ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        
                                        <?php if (($host['secure_boot_status'] ?? '') !== 'disabled'): ?>
                                        <form method="post" style="display: inline"><?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_secure_boot">
                                            <input type="hidden" name="mac" value="<?php echo h(formatMac($host['mac_address'])); ?>">
                                            <input type="hidden" name="secure_boot" value="disable">
                                            <button type="submit" class="btn btn-warning" data-confirm="Are you sure you want to disable secure boot for this host?">
                                                <i class="fas fa-shield-alt"></i> Disable SB
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="post" style="display: inline"><?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_secure_boot">
                                            <input type="hidden" name="mac" value="<?php echo h(formatMac($host['mac_address'])); ?>">
                                            <input type="hidden" name="secure_boot" value="enable">
                                            <button type="submit" class="btn btn-success" data-confirm="Are you sure you want to enable secure boot for this host?">
                                                <i class="fas fa-shield-alt"></i> Enable SB
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if (($host['ilo_ip'] ?? '') !== ''): ?>
                                        <form method="post" style="display: inline"><?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="network_boot">
                                            <input type="hidden" name="mac" value="<?php echo h(formatMac($host['mac_address'])); ?>">
                                            <button type="submit" class="btn btn-secondary" data-confirm="Boot this host from the network now? It will be powered on, or restarted if it is already running.">
                                                <i class="fas fa-network-wired"></i> Net Boot
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <button class="btn btn-danger" data-delete-host="<?php echo jsValue(['mac' => formatMac($host['mac_address']), 'hostname' => $host['hostname'] ?? '']); ?>">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Pending Hosts Tab Content -->
            <div class="tab-pane fade" id="pending" role="tabpanel" aria-labelledby="pending-tab">
                <?php if (empty($pendingHosts)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No pending hosts found.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>MAC Address</th>
                                <th>Serial Number</th>
                                <th>Last Seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingHosts as $host): ?>
                            <tr>
                                <td><?php echo h(formatMac($host['mac_address'])); ?></td>
                                <td><?php echo h($host['serial_number'] ?? 'Unknown'); ?></td>
                                <td><?php echo h($host['last_seen'] ?? 'Unknown'); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-success" data-approve-host="<?php echo jsValue([
                                            'mac'      => formatMac($host['mac_address']),
                                            'hostname' => $host['hostname'] ?: ('esxi-' . substr(str_replace(':', '', formatMac($host['mac_address'])), -6)),
                                            'serial'   => $host['serial_number'] ?? '',
                                        ]); ?>">
                                            <i class="fas fa-check-circle"></i> Configure & Approve
                                        </button>
                                        
                                        <button class="btn btn-danger" data-delete-host="<?php echo jsValue(['mac' => formatMac($host['mac_address']), 'hostname' => 'Pending host']); ?>">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Deploying/Deployed Hosts Tab Content -->
            <div class="tab-pane fade" id="deployed" role="tabpanel" aria-labelledby="deployed-tab">
                <?php if (empty($deployingHosts) && empty($deployedHosts)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hosts are currently being deployed or have been deployed.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Hostname</th>
                                <th>MAC Address</th>
                                <th>ESX Management IP</th>
                                <th>Status</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_merge($deployingHosts, $deployedHosts) as $host): ?>
                            <tr>
                                <td><?php echo h($host['hostname']); ?></td>
                                <td><?php echo h(formatMac($host['mac_address'])); ?></td>
                                <td><?php echo h($host['management_ip']); ?></td>
                                <td>
                                    <?php
                                    $isDeploying = $host['deployment_status'] === 'deploying';
                                    $progress = max(0, min(100, (int)($host['progress'] ?? 0)));
                                    $progressText = (string)($host['progress_text'] ?? '');
                                    ?>
                                    <?php if ($isDeploying): ?>
                                        <span class="badge bg-primary">Deploying</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Deployed</span>
                                    <?php endif; ?>

                                    <?php if ($isDeploying): ?>
                                    <!-- Refreshed in place by the poller below; a deploying host is
                                         the one row an operator actually watches. -->
                                    <div class="progress mt-1" style="height: 1.1rem"
                                         data-progress-for="<?php echo h(formatMac($host['mac_address'])); ?>">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                                             role="progressbar"
                                             style="width: <?php echo $progress; ?>%"
                                             aria-valuenow="<?php echo $progress; ?>"
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?php echo $progress; ?>%
                                        </div>
                                    </div>
                                    <small class="text-muted"
                                           data-progress-text-for="<?php echo h(formatMac($host['mac_address'])); ?>">
                                        <?php echo h($progressText); ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($host['deployment_status'] === 'deploying') {
                                        echo h($host['deployment_started'] ?? 'Unknown');
                                    } else {
                                        echo h($host['deployment_time'] ?? 'Unknown');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-primary" data-edit-host="<?php echo jsValue([
                                            'mac'             => formatMac($host['mac_address']),
                                            'fqdn'            => $host['fqdn'] ?: (($host['hostname'] ?? 'esxi') . '.local'),
                                            'serial'          => $host['serial_number'] ?? '',
                                            'iloIp'           => $host['ilo_ip'] ?? '',
                                            'mgmtIp'          => $host['management_ip'] ?? '',
                                            'mgmtNetmask'     => $host['management_netmask'] ?? '255.255.255.0',
                                            'mgmtGateway'     => $host['management_gateway'] ?? '',
                                            'vlanMgmt'        => (int)($host['vlans']['management'] ?? 0),
                                            'vlanVmotion'     => (int)($host['vlans']['vmotion'] ?? 0),
                                            'vmotionIp'       => $host['vmotion_ip'] ?? '',
                                            'vmotionNetmask'  => $host['vmotion_netmask'] ?? '255.255.255.0',
                                            'deploymentType'  => $host['deployment_type'] ?? 'standard',
                                            'esxiVersion'     => $host['esxi_version'] ?? '',
                                        ]); ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        
                                        <?php if ($host['deployment_status'] === 'deployed'): ?>
                                        <form method="post" style="display: inline"><?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="reinstall_host">
                                            <input type="hidden" name="mac" value="<?php echo h(formatMac($host['mac_address'])); ?>">
                                            <button type="submit" class="btn btn-warning" data-confirm="Are you sure you want to reinstall this host? It will need to be PXE booted again.">
                                                <i class="fas fa-sync-alt"></i> Reinstall
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-danger" data-delete-host="<?php echo jsValue(['mac' => formatMac($host['mac_address']), 'hostname' => $host['hostname'] ?? '']); ?>">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
    
    <!-- Delete host form (hidden) -->
    <form id="delete-host-form" method="post" style="display: none;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="delete_host">
        <input type="hidden" name="mac" id="delete-mac">
    </form>
    
<!-- Approve Host Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approveModalLabel">Approve Host for Deployment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" id="approve-host-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="approve_host">
                    <input type="hidden" name="mac" id="approve-mac">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">MAC Address:</label>
                            <div class="form-control-plaintext" id="approve-mac-display"></div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Serial Number:</label>
                            <div class="form-control-plaintext" id="approve-serial"></div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="approve-hostname" class="form-label required">Hostname:</label>
                            <input type="text" class="form-control" id="approve-hostname" name="hostname" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="approve-fqdn" class="form-label">FQDN:</label>
                            <input type="text" class="form-control" id="approve-fqdn" name="fqdn">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label for="approve-deployment-type" class="form-label">Deployment Type:</label>
                            <select class="form-select" id="approve-deployment-type" name="deployment_type">
                                <option value="standard">Standard ESXi</option>
                                <option value="vcf">VMware Cloud Foundation (VCF)</option>
                            </select>
                        </div>
                    </div>
                    
                    <h5 class="mt-4 mb-3 border-bottom pb-2">Network Configuration</h5>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="approve-vlan-mgmt" class="form-label">ESX Management VLAN:</label>
                            <input type="number" class="form-control" id="approve-vlan-mgmt" name="vlan_mgmt" min="0" max="4094" value="0">
                        </div>
                        
                        <div class="col-md-8 row">
                            <div class="col-md-4">
                                <label for="approve-management-ip" class="form-label required">ESX Management IP:</label>
                                <input type="text" class="form-control" id="approve-management-ip" name="management_ip" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="approve-management-netmask" class="form-label">ESX Management Netmask:</label>
                                <input type="text" class="form-control" id="approve-management-netmask" name="management_netmask" value="255.255.255.0">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="approve-management-gateway" class="form-label required">ESX Management Gateway:</label>
                                <input type="text" class="form-control" id="approve-management-gateway" name="management_gateway" required>
                            </div>
                        </div>
                    </div>
                    
                    <div id="approve-vmotion-section">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="approve-vlan-vmotion" class="form-label">vMotion VLAN:</label>
                                <input type="number" class="form-control" id="approve-vlan-vmotion" name="vlan_vmotion" min="0" max="4094" value="0">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="approve-vmotion-ip" class="form-label">vMotion IP:</label>
                                <input type="text" class="form-control" id="approve-vmotion-ip" name="vmotion_ip">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="approve-vmotion-netmask" class="form-label">vMotion Netmask:</label>
                                <input type="text" class="form-control" id="approve-vmotion-netmask" name="vmotion_netmask" value="255.255.255.0">
                           </div>
                       </div>
                   </div>
                   
                   <h5 class="mt-4 mb-3 border-bottom pb-2">Credentials</h5>

                   <div class="row">
                       <div class="col-md-12 mb-2">
                           <div class="form-check">
                               <input class="form-check-input" type="checkbox" id="approve-use-custom-ilo" name="use_custom_ilo" 
                                      onchange="toggleApproveIloCredFields(this.checked)">
                               <label class="form-check-label" for="approve-use-custom-ilo">
                                   Use custom iLO credentials
                               </label>
                           </div>
                       </div>
                   </div>

                   <div id="approve-ilo-credentials-fields" style="display: none;">
                       <div class="row mb-3">
                           <div class="col-md-6">
                               <label for="approve-ilo-username" class="form-label">iLO Username:</label>
                               <input type="text" class="form-control" id="approve-ilo-username" name="ilo_username" 
                                      placeholder="Default: Administrator">
                               <div class="form-text">Leave blank to use global default username</div>
                           </div>
                           
                           <div class="col-md-6">
                               <label for="approve-ilo-password" class="form-label">iLO Password:</label>
                               <input type="password" class="form-control" id="approve-ilo-password" name="ilo_password">
                               <div class="form-text">Leave blank to use global default password</div>
                           </div>
                       </div>
                   </div>

                   <div class="row">
                       <div class="col-md-12 mb-2">
                           <div class="form-check">
                               <input class="form-check-input" type="checkbox" id="approve-use-custom-esxi" name="use_custom_esxi" 
                                      onchange="toggleApproveEsxiCredFields(this.checked)">
                               <label class="form-check-label" for="approve-use-custom-esxi">
                                   Use custom ESXi root password
                               </label>
                           </div>
                       </div>
                   </div>

                   <div id="approve-esxi-credentials-fields" style="display: none;">
                       <div class="row mb-3">
                           <div class="col-md-6">
                               <label for="approve-esxi-password" class="form-label">ESXi Root Password:</label>
                               <input type="password" class="form-control" id="approve-esxi-password" name="esxi_password">
                               <div class="form-text">Leave blank to use global default password</div>
                           </div>
                       </div>
                   </div>
                   
                   <div class="d-flex justify-content-end mt-4">
                       <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                       <button type="submit" class="btn btn-success">Approve for Deployment</button>
                   </div>
               </form>
           </div>
       </div>
   </div>
</div>

   <script>
   // Toggle functions for custom credentials
   function toggleIloCredFields(show) {
       document.getElementById('ilo_credentials_fields').style.display = show ? 'block' : 'none';
   }

   function toggleEsxiCredFields(show) {
       document.getElementById('esxi_credentials_fields').style.display = show ? 'block' : 'none';
   }

   function toggleApproveIloCredFields(show) {
       document.getElementById('approve-ilo-credentials-fields').style.display = show ? 'block' : 'none';
   }

   function toggleApproveEsxiCredFields(show) {
       document.getElementById('approve-esxi-credentials-fields').style.display = show ? 'block' : 'none';
   }
   </script>
   <?php
}

// Process hosts-specific actions
function processHostsActions($action, $postData) {
   $result = [
       'message' => '',
       'error' => ''
   ];
   
   switch ($action) {
       case 'add_host':
           // Add or update host function
           $result = processAddHostAction($postData);
           break;
           
       case 'delete_host':
           // Delete host function
           $result = processDeleteHostAction($postData);
           break;
           
       case 'toggle_secure_boot':
           // Toggle secure boot status
           $result = processSecureBootAction($postData);
           break;

       case 'network_boot':
           // Set a one-time network boot and power the host
           $result = processNetworkBootAction($postData);
           break;
           
       case 'approve_host':
           // Approve a host for deployment
           $result = processApproveHostAction($postData);
           break;
           
       case 'reinstall_host':
           // Mark a host for reinstallation
           $result = processReinstallHostAction($postData);
           break;
   }
   
   return $result;
}


