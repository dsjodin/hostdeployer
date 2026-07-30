<?php
/**
 * ESXi Auto-deployment Admin - Settings Tab
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * Render the System Settings Tab
 * 
 * @param array $globalConfig Global configuration
 */
function renderSettingsContent($globalConfig) {
  // Load credentials
    $credentials = storeLoadCredentials();
    ?>
    <div class="row">
        <div class="col-12 mb-4">
            <h1 class="h3 text-gray-800">System Settings</h1>
        </div>
    </div>
    
    <?php if ($globalConfig): ?>
    
    <!-- Settings Tabs -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="settingsTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" 
                                    type="button" role="tab" aria-controls="general" aria-selected="true">
                                <i class="fas fa-sliders-h me-1"></i> General Settings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network" 
                                    type="button" role="tab" aria-controls="network" aria-selected="false">
                                <i class="fas fa-network-wired me-1"></i> Network
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="templates-tab" data-bs-toggle="tab" data-bs-target="#templates" 
                                    type="button" role="tab" aria-controls="templates" aria-selected="false">
                                <i class="fas fa-file-code me-1"></i> Templates
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" 
                                    type="button" role="tab" aria-controls="logs" aria-selected="false">
                                <i class="fas fa-list-alt me-1"></i> System Logs
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="settingsTabContent">
<!-- General Settings Tab -->
                        <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="m-0 font-weight-bold text-primary">iLO Settings</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="save_global_config">
                                                
                                                <div class="mb-3">
                                                    <label for="ilo_user" class="form-label">iLO Username:</label>
                                                    <input type="text" class="form-control" id="ilo_user" name="ilo_user" 
                                                           value="<?php echo h($globalConfig['ilo']['admin_user']); ?>" required>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="ilo_password" class="form-label">iLO Password:</label>
                                                    <input type="password" class="form-control" id="ilo_password" name="ilo_password"
                                                           placeholder="<?php echo empty($globalConfig['ilo']['admin_password']) ? 'Not set' : 'Unchanged'; ?>"
                                                           autocomplete="new-password">
                                                    <div class="form-text text-muted">Leave blank to keep the current password.</div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label for="ilo_scan_start" class="form-label">iLO Scan Range Start:</label>
                                                        <input type="text" class="form-control" id="ilo_scan_start" name="ilo_scan_start" 
                                                               value="<?php echo h($globalConfig['ilo']['scan_range_start']); ?>" required>
                                                    </div>
                                                    
                                                    <div class="col-md-6 mb-3">
                                                        <label for="ilo_scan_end" class="form-label">iLO Scan Range End:</label>
                                                        <input type="text" class="form-control" id="ilo_scan_end" name="ilo_scan_end" 
                                                               value="<?php echo h($globalConfig['ilo']['scan_range_end']); ?>" required>
                                                    </div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-1"></i> Save iLO Settings
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Add this inside the "General Settings" tab content in settings.php -->
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="m-0 font-weight-bold text-primary">Default Credentials</h5>
                                    </div>
                                    <div class="card-body">
                                        <p>Set the default credentials to use when no host-specific credentials are configured.</p>
                                        
                                        <form method="post">
                                                <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="save_default_credentials">
                                            
                                            <div class="row mb-4">
                                                <div class="col-md-6">
                                                    <h6>iLO Default Credentials</h6>
                                                    <div class="mb-3">
                                                        <label for="default_ilo_username" class="form-label">Default iLO Username:</label>
                                                        <input type="text" class="form-control" id="default_ilo_username" name="default_ilo_username" 
                                                               value="<?php echo h($credentials['ilo']['admin_user'] ?? 'Administrator'); ?>">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="default_ilo_password" class="form-label">Default iLO Password:</label>
                                                        <input type="password" class="form-control" id="default_ilo_password" name="default_ilo_password"
                                                               placeholder="<?php echo empty($credentials['ilo']['admin_password']) ? 'Not set' : 'Unchanged'; ?>"
                                                               autocomplete="new-password">
                                                        <div class="form-text text-muted">Leave blank to keep the current password.</div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <h6>ESXi Default Credentials</h6>
                                                    <div class="mb-3">
                                                        <label for="default_esxi_password" class="form-label">Default ESXi Root Password:</label>
                                                        <input type="password" class="form-control" id="default_esxi_password" name="default_esxi_password"
                                                               placeholder="<?php echo empty($credentials['esxi']['root_password']) ? 'Not set' : 'Unchanged'; ?>"
                                                               autocomplete="new-password">
                                                        <div class="form-text text-muted">Leave blank to keep the current password.</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Save Default Credentials
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                
                                
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="m-0 font-weight-bold text-primary">Auto-Registration Settings</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="save_auto_registration">
                                                
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="auto_registration_enabled" 
                                                           name="auto_registration_enabled" 
                                                           <?php echo ($globalConfig['deployment']['auto_registration']['enabled'] ?? false) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="auto_registration_enabled">
                                                        Enable Auto-Registration
                                                    </label>
                                                    <div class="form-text text-muted">When enabled, new servers will automatically register and wait for approval</div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="default_status" class="form-label">Default Status:</label>
                                                    <select class="form-select" id="default_status" name="default_status">
                                                        <option value="pending" <?php echo ($globalConfig['deployment']['auto_registration']['default_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>
                                                            Pending
                                                        </option>
                                                        <option value="approved" <?php echo ($globalConfig['deployment']['auto_registration']['default_status'] ?? '') === 'approved' ? 'selected' : ''; ?>>
                                                            Approved (Caution: Auto-deploys)
                                                        </option>
                                                    </select>
                                                    <div class="form-text text-muted">Initial status for auto-registered servers</div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label for="max_wait_time" class="form-label">Maximum Wait Time (seconds):</label>
                                                        <input type="number" class="form-control" id="max_wait_time" name="max_wait_time" 
                                                               value="<?php echo $globalConfig['deployment']['auto_registration']['max_wait_time'] ?? 7200; ?>" 
                                                               min="300" step="60">
                                                        <div class="form-text text-muted">How long servers will wait for approval</div>
                                                    </div>
                                                    
                                                    <div class="col-md-6 mb-3">
                                                        <label for="retry_interval" class="form-label">Retry Interval (seconds):</label>
                                                        <input type="number" class="form-control" id="retry_interval" name="retry_interval" 
                                                               value="<?php echo $globalConfig['deployment']['auto_registration']['retry_interval'] ?? 60; ?>" 
                                                               min="30" max="600" step="10">
                                                        <div class="form-text text-muted">Time between status checks</div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="notification_email" class="form-label">Notification Email:</label>
                                                    <input type="email" class="form-control" id="notification_email" name="notification_email" 
                                                           value="<?php echo h($globalConfig['deployment']['auto_registration']['notification_email'] ?? ''); ?>">
                                                    <div class="form-text text-muted">Email for new server notifications (leave empty to disable)</div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-1"></i> Save Auto-Registration Settings
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="m-0 font-weight-bold text-primary">Security Settings</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="save_security_settings">
                                                
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="secure_boot_enabled" 
                                                           name="secure_boot_enabled" 
                                                           <?php echo $globalConfig['security']['secure_boot_enabled'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="secure_boot_enabled">
                                                        Enable Secure Boot
                                                    </label>
                                                    <div class="form-text text-muted">
                                                        Temporarily disable Secure Boot during deployment and re-enable it afterward
                                                    </div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-1"></i> Save Security Settings
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Network Settings Tab -->
                        <div class="tab-pane fade" id="network" role="tabpanel" aria-labelledby="network-tab">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="m-0 font-weight-bold text-primary">Network Configuration</h5>
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                                <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="save_network_settings">
                                        
                                        <div class="row">
                                            <div class="col-md-3 mb-3">
                                                <label for="dhcp_start" class="form-label">DHCP Range Start:</label>
                                                <input type="text" class="form-control" id="dhcp_start" name="dhcp_start" 
                                                       value="<?php echo h($globalConfig['network']['dhcp_range_start']); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-3 mb-3">
                                                <label for="dhcp_end" class="form-label">DHCP Range End:</label>
                                                <input type="text" class="form-control" id="dhcp_end" name="dhcp_end" 
                                                       value="<?php echo h($globalConfig['network']['dhcp_range_end']); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-3 mb-3">
                                                <label for="subnet_mask" class="form-label">Subnet Mask:</label>
                                                <input type="text" class="form-control" id="subnet_mask" name="subnet_mask" 
                                                       value="<?php echo h($globalConfig['network']['subnet_mask']); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-3 mb-3">
                                                <label for="gateway" class="form-label">Gateway:</label>
                                                <input type="text" class="form-control" id="gateway" name="gateway" 
                                                       value="<?php echo h($globalConfig['network']['gateway']); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="dns_servers" class="form-label">DNS Servers (comma separated):</label>
                                                <input type="text" class="form-control" id="dns_servers" name="dns_servers" 
                                                       value="<?php echo h(implode(', ', $globalConfig['network']['dns_servers'])); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label for="ntp_servers" class="form-label">NTP Servers (comma separated):</label>
                                                <input type="text" class="form-control" id="ntp_servers" name="ntp_servers" 
                                                       value="<?php echo h(implode(', ', $globalConfig['network']['ntp_servers'])); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="webserver_ip" class="form-label">Web Server IP:</label>
                                            <input type="text" class="form-control" id="webserver_ip" name="webserver_ip" 
                                                   value="<?php echo h($globalConfig['webserver']['ip']); ?>" required>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Save Network Settings
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
<!-- Templates Tab -->
                        <div class="tab-pane fade" id="templates" role="tabpanel" aria-labelledby="templates-tab">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="m-0 font-weight-bold text-primary">Kickstart Templates</h5>
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                                <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="save_kickstart_templates">
                                        
                                        <div class="mb-3">
                                            <label for="std_template" class="form-label">Standard ESXi Template Path:</label>
                                            <input type="text" class="form-control" id="std_template" name="std_template" 
                                                   value="<?php echo h($globalConfig['deployment']['kickstart_templates']['standard'] ?? '/srv/autodeploy/templates/kickstart_template_std.cfg'); ?>" required>
                                            <div class="form-text text-muted">Path to kickstart template for standard ESXi deployments</div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="vcf_template" class="form-label">VCF Template Path:</label>
                                            <input type="text" class="form-control" id="vcf_template" name="vcf_template" 
                                                   value="<?php echo h($globalConfig['deployment']['kickstart_templates']['vcf'] ?? '/srv/autodeploy/templates/kickstart_template_vcf.cfg'); ?>" required>
                                            <div class="form-text text-muted">Path to kickstart template for VMware Cloud Foundation deployments</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="waiting_template" class="form-label">Waiting Template Path:</label>
                                            <input type="text" class="form-control" id="waiting_template" name="waiting_template" 
                                                   value="<?php echo h($globalConfig['deployment']['waiting_template_path'] ?? '/srv/autodeploy/templates/waiting_template.cfg'); ?>" required>
                                            <div class="form-text text-muted">Path to template shown to servers waiting for approval</div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Save Template Settings
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php
                            // Uploading the media is the supported way to add a
                            // version; the manual form below stays for a
                            // directory that was placed on the server by hand.
                            $extractor = imageAvailableExtractor();
                            ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="m-0 font-weight-bold text-primary">Upload ESXi Media</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($extractor === null): ?>
                                        <div class="alert alert-warning mb-0">
                                            <strong>No extraction tool is installed.</strong>
                                            Install one of
                                            <code><?php echo h(implode('</code>, <code>', array_keys(imageExtractorCandidates()))); ?></code>
                                            to upload ISOs here. Until then, extract the media on the server
                                            and register the directory with the form below.
                                        </div>
                                    <?php else: ?>
                                        <p class="card-text">
                                            Upload an ESXi installer ISO. It is verified against the checksum you
                                            supply, extracted, checked for a usable <code>boot.cfg</code> and
                                            registered — replacing the mount, copy and hand-edit that this used
                                            to take.
                                        </p>

                                        <form method="post" enctype="multipart/form-data">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="upload_esxi_image">

                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label for="image_version" class="form-label">Version name:</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                               id="image_version" name="version"
                                                               pattern="[A-Za-z0-9._\-]+" required
                                                               placeholder="8.0U3">
                                                        <div class="form-text">Becomes a directory and a URL path.</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="image_description" class="form-label">Description:</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                               id="image_description" name="description"
                                                               placeholder="ESXi 8.0 Update 3">
                                                    </div>
                                                </div>
                                                <div class="col-md-5">
                                                    <div class="mb-3">
                                                        <label for="image_sha256" class="form-label">SHA-256 (recommended):</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                               id="image_sha256" name="sha256"
                                                               pattern="[0-9a-fA-F]{64}"
                                                               placeholder="from the Broadcom download page">
                                                        <div class="form-text">
                                                            Left empty the image is installed unverified.
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="image_file" class="form-label">ISO file:</label>
                                                <input type="file" class="form-control" id="image_file"
                                                       name="image" accept=".iso,application/x-iso9660-image" required>
                                                <div class="form-text">
                                                    Several gigabytes; the upload and extraction take a few minutes.
                                                    Extracting with <code><?php echo h($extractor); ?></code>.
                                                </div>
                                            </div>

                                            <button type="submit" class="btn btn-primary"
                                                    data-confirm="Uploading and extracting takes several minutes. Do not navigate away.">
                                                <i class="fas fa-upload"></i> Upload and install
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="m-0 font-weight-bold text-primary">ESXi Versions</h5>
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                                <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="save_esxi_versions">
                                        
                                        <div class="mb-3">
                                            <label for="default_version" class="form-label">Default ESXi Version:</label>
                                            <select class="form-select" id="default_version" name="default_version">
                                                <?php foreach ($globalConfig['deployment']['esxi_versions'] as $version => $versionConfig): ?>
                                                <option value="<?php echo h($version); ?>" 
                                                        <?php echo ($globalConfig['deployment']['default_version'] == $version) ? 'selected' : ''; ?>>
                                                    <?php echo h($version); ?> - <?php echo h($versionConfig['description']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Configured ESXi Versions:</label>
                                            <div class="table-responsive">
                                                <table class="table table-bordered">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Version</th>
                                                            <th>Path</th>
                                                            <th>Description</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($globalConfig['deployment']['esxi_versions'] as $version => $versionConfig): ?>
                                                        <tr>
                                                            <td><?php echo h($version); ?></td>
                                                            <td><?php echo h($versionConfig['path']); ?></td>
                                                            <td><?php echo h($versionConfig['description']); ?></td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-danger" 
                                                                        onclick="if(confirm('Are you sure you want to remove this version?')) { document.getElementById('remove_version').value = '<?php echo h($version); ?>'; this.form.submit(); }">
                                                                    <i class="fas fa-trash-alt"></i> Remove
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <input type="hidden" name="remove_version" id="remove_version" value="">
                                        
                                        <div class="alert alert-light border mb-3">
                                            <h6 class="alert-heading">Add New ESXi Version</h6>
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="mb-2">
                                                        <label for="new_version" class="form-label">Version Name:</label>
                                                        <input type="text" class="form-control form-control-sm" id="new_version" name="new_version" 
                                                               placeholder="e.g. 8.0U1">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-2">
                                                        <label for="new_version_path" class="form-label">ESXi Path:</label>
                                                        <input type="text" class="form-control form-control-sm" id="new_version_path" name="new_version_path" 
                                                               placeholder="/srv/autodeploy/esxi/8.0U1">
                                                    </div>
                                                </div>
                                                <div class="col-md-5">
                                                    <div class="mb-2">
                                                        <label for="new_version_desc" class="form-label">Description:</label>
                                                        <input type="text" class="form-control form-control-sm" id="new_version_desc" name="new_version_desc" 
                                                               placeholder="ESXi 8.0 Update 1">
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-success" name="add_new_version" value="1">
                                                <i class="fas fa-plus-circle"></i> Add Version
                                            </button>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary" name="save_version_settings" value="1">
                                            <i class="fas fa-save me-1"></i> Save Version Settings
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Logs Tab -->
                        <div class="tab-pane fade" id="logs" role="tabpanel" aria-labelledby="logs-tab">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="m-0 font-weight-bold text-primary">System Logs</h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $logFiles = [];
                                    $logsDir = $globalConfig['paths']['logs_dir'] ?? '/srv/autodeploy/logs';
                                    
                                    if (is_dir($logsDir)) {
                                        $files = glob("$logsDir/*.log");
                                        foreach ($files as $file) {
                                            $logFiles[] = basename($file);
                                        }
                                        // Sort files alphabetically
                                        sort($logFiles);
                                    }
                                    ?>
                                    
                                    <?php if (empty($logFiles)): ?>
                                    <p>No log files found.</p>
                                    <?php else: ?>
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="log-file" class="form-label">Select Log File:</label>
                                                <select class="form-select" id="log-file" onchange="loadLogFile(this.value)">
                                                    <option value="">Select a log file...</option>
                                                    <?php foreach ($logFiles as $file): ?>
                                                    <option value="<?php echo h($file); ?>"><?php echo h($file); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-end mt-4">
                                                <button id="clear-log-filter" class="btn btn-outline-secondary me-2">
                                                    <i class="fas fa-eraser me-1"></i> Clear Filter
                                                </button>
                                                <button id="download-log" class="btn btn-outline-primary">
                                                    <i class="fas fa-download me-1"></i> Download Log
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-5">
                                            <div class="mb-3">
                                                <label for="log-filter" class="form-label">Filter Logs:</label>
                                                <input type="text" class="form-control" id="log-filter" placeholder="Type to filter logs...">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="log-level" class="form-label">Log Level:</label>
                                                <select class="form-select" id="log-level">
                                                    <option value="">All Levels</option>
                                                    <option value="INFO">INFO</option>
                                                    <option value="WARNING">WARNING</option>
                                                    <option value="ERROR">ERROR</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label for="log-lines" class="form-label">Lines:</label>
                                                <select class="form-select" id="log-lines">
                                                    <option value="50">50</option>
                                                    <option value="100" selected>100</option>
                                                    <option value="250">250</option>
                                                    <option value="500">500</option>
                                                    <option value="1000">1000</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-check form-switch mt-4">
                                                <input class="form-check-input" type="checkbox" id="auto-refresh-logs">
                                                <label class="form-check-label" for="auto-refresh-logs">
                                                    Auto-refresh
                                                    <span id="refresh-indicator" style="display: none;" class="refresh-indicator"></span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div id="log-viewer-container">
                                        <pre id="log-content" class="log-content">Select a log file to view its contents</pre>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow mb-4">
        <div class="card-header">
            <h5 class="m-0 font-weight-bold text-primary">System Setup Required</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i> 
                One or more critical configuration files are missing. Please follow these setup instructions:
            </div>
            
            <ol>
                <li>Create the configuration directory: <code>mkdir -p /srv/autodeploy/config</code></li>
                <li>Create the logs directory: <code>mkdir -p /srv/autodeploy/logs</code></li>
                <li>Create a global configuration file at <code>/srv/autodeploy/config/global_config.json</code></li>
                <li>Create an empty hosts configuration file at <code>/srv/autodeploy/config/hosts.json</code></li>
            </ol>
            
            <p>The global configuration file should contain the correct values for your environment.
               The template below is just a reference. <strong>Do not copy this directly without reviewing all values!</strong></p>
            
            <div class="card">
                <div class="card-body">
                    <pre class="config-template">{
  "deployment": {
    "esxi_versions": {
      "8.0U3": {
        "path": "/srv/autodeploy/esxi/8.0U3",
        "description": "ESXi 8.0 Update 3",
        "bootloader_url": "http://YOUR_WEBSERVER_IP/esxi/8.0U3/efi/boot/bootx64.efi"
      },
      "7.0": {
        "path": "/srv/autodeploy/esxi/7.0",
        "description": "ESXi 7.0 Update 3",
        "bootloader_url": "http://YOUR_WEBSERVER_IP/esxi/7.0/efi/boot/bootx64.efi"
      }
    },
    "kickstart_templates": {
      "standard": "/srv/autodeploy/templates/kickstart_template_std.cfg",
      "vcf": "/srv/autodeploy/templates/kickstart_template_vcf.cfg"
    },
    "default_version": "8.0U3",
    "default_deployment_type": "standard",
    "auto_registration": {
      "enabled": true,
      "default_status": "pending",
      "max_wait_time": 7200,
      "retry_interval": 60,
      "notification_email": "admin@example.com"
    },
    "esxi_root_password": "YOUR_SECURE_PASSWORD"
  },
  "network": {
    "dhcp_range_start": "YOUR_DHCP_START_IP",
    "dhcp_range_end": "YOUR_DHCP_END_IP",
    "subnet_mask": "YOUR_SUBNET_MASK",
    "gateway": "YOUR_GATEWAY_IP",
    "dns_servers": ["PRIMARY_DNS_IP", "SECONDARY_DNS_IP"],
    "ntp_servers": ["NTP_SERVER1", "NTP_SERVER2"]
  },
  "ilo": {
    "admin_user": "YOUR_ILO_USERNAME",
    "admin_password": "YOUR_ILO_PASSWORD",
    "scan_range_start": "YOUR_ILO_SCAN_START_IP",
    "scan_range_end": "YOUR_ILO_SCAN_END_IP"
  },
  "webserver": {
    "ip": "YOUR_WEBSERVER_IP",
    "port": 80,
    "url": "http://YOUR_WEBSERVER_IP"
  },
  "security": {
    "secure_boot_enabled": true
  },
  "paths": {
    "hosts_config": "/srv/autodeploy/config/hosts.json",
    "logs_dir": "/srv/autodeploy/logs"
  }
}</pre>
                </div>
            </div>
            
            <div class="mt-3">
                <p>The hosts configuration file should be initialized with an empty hosts array:</p>
                
                <div class="card">
                    <div class="card-body">
                        <pre class="config-template">{
  "hosts": []
}</pre>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle me-2"></i> After creating these files with values specific to your environment, reload this page.
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
    </script>
    <?php
}

// Process settings-specific actions
function processSettingsActions($action, $postData) {
    $result = [
        'message' => '',
        'error' => ''
    ];
    
    $globalConfigPath = '/srv/autodeploy/config/global_config.json';
    
    switch ($action) {
        case 'save_auto_registration':
            // Save auto-registration configuration
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update auto-registration settings - global configuration not found";
                break;
            }
            
            // Initialize auto_registration section if it doesn't exist
            if (!isset($globalConfig['deployment']['auto_registration'])) {
                $globalConfig['deployment']['auto_registration'] = [];
            }
            
            // Update settings
            $globalConfig['deployment']['auto_registration']['enabled'] = isset($postData['auto_registration_enabled']);
            $globalConfig['deployment']['auto_registration']['default_status'] = $postData['default_status'];
            $globalConfig['deployment']['auto_registration']['max_wait_time'] = (int)$postData['max_wait_time'];
            $globalConfig['deployment']['auto_registration']['retry_interval'] = (int)$postData['retry_interval'];
            $globalConfig['deployment']['auto_registration']['notification_email'] = $postData['notification_email'];
            
            // Validate values
            if ($globalConfig['deployment']['auto_registration']['max_wait_time'] < 300) {
                $globalConfig['deployment']['auto_registration']['max_wait_time'] = 300; // Minimum 5 minutes
            }
            
            if ($globalConfig['deployment']['auto_registration']['retry_interval'] < 30) {
                $globalConfig['deployment']['auto_registration']['retry_interval'] = 30; // Minimum 30 seconds
            }
            
            // Save the updated configuration
            if (saveJsonConfig($globalConfigPath, $globalConfig)) {
                $result['message'] = "Auto-registration settings saved successfully";
            } else {
                $result['error'] = "Failed to save auto-registration settings";
            }
            break;
            
        case 'save_security_settings':
            // Save security settings
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update security settings - global configuration not found";
                break;
            }
            
            // Update security settings
            $globalConfig['security']['secure_boot_enabled'] = isset($postData['secure_boot_enabled']);
            
            // Save the updated configuration
            if (saveJsonConfig($globalConfigPath, $globalConfig)) {
                $result['message'] = "Security settings saved successfully";
            } else {
                $result['error'] = "Failed to save security settings";
            }
            break;
            
        case 'save_network_settings':
            // Save network settings
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update network settings - global configuration not found";
                break;
            }

            // Validate everything *before* touching the DHCP server. The old
            // code shelled out first and only then looked at the values, so a
            // typo could take DHCP down and still be written to the config.
            $dhcpStart   = trim((string)($postData['dhcp_start'] ?? ''));
            $dhcpEnd     = trim((string)($postData['dhcp_end'] ?? ''));
            $subnetMask  = trim((string)($postData['subnet_mask'] ?? ''));
            $gateway     = trim((string)($postData['gateway'] ?? ''));
            $webserverIp = trim((string)($postData['webserver_ip'] ?? ''));

            $dnsServers = array_values(array_filter(array_map('trim', explode(',', (string)($postData['dns_servers'] ?? ''))), 'strlen'));
            $ntpServers = array_values(array_filter(array_map('trim', explode(',', (string)($postData['ntp_servers'] ?? ''))), 'strlen'));

            $validationError = '';

            foreach ([
                'DHCP range start' => $dhcpStart,
                'DHCP range end'   => $dhcpEnd,
                'gateway'          => $gateway,
                'web server IP'    => $webserverIp,
            ] as $label => $value) {
                if (!isValidIpv4($value)) {
                    $validationError = "Invalid $label address";
                    break;
                }
            }

            if ($validationError === '' && !isValidNetmask($subnetMask)) {
                $validationError = 'Invalid subnet mask';
            }

            if ($validationError === '' && ip2long($dhcpStart) > ip2long($dhcpEnd)) {
                $validationError = 'The DHCP range start must not be higher than the range end';
            }

            if ($validationError === '' && $dnsServers === []) {
                $validationError = 'At least one DNS server is required';
            }

            foreach ($dnsServers as $dns) {
                if ($validationError === '' && !isValidIp($dns)) {
                    $validationError = "Invalid DNS server address: $dns";
                }
            }

            foreach ($ntpServers as $ntp) {
                if ($validationError === '' && !isValidIp($ntp) && !isValidHostname($ntp)) {
                    $validationError = "Invalid NTP server: $ntp";
                }
            }

            if ($validationError !== '') {
                $result['error'] = $validationError;
                break;
            }

            // Persist the configuration first, so the stored state always
            // matches what we asked the DHCP server to serve.
            $globalConfig['network']['dhcp_range_start'] = $dhcpStart;
            $globalConfig['network']['dhcp_range_end'] = $dhcpEnd;
            $globalConfig['network']['subnet_mask'] = $subnetMask;
            $globalConfig['network']['gateway'] = $gateway;
            $globalConfig['network']['dns_servers'] = $dnsServers;
            $globalConfig['network']['ntp_servers'] = $ntpServers;
            $globalConfig['webserver']['ip'] = $webserverIp;
            $globalConfig['webserver']['url'] = 'http://' . $webserverIp;

            if (!saveJsonConfig($globalConfigPath, $globalConfig)) {
                $result['error'] = "Failed to save network settings";
                break;
            }

            // Update the DHCP server through the restricted sudo helper.
            $command = sprintf(
                'sudo -n /usr/local/bin/update_dhcp_config.sh %s %s %s %s %s %s 2>&1',
                escapeshellarg($dhcpStart),
                escapeshellarg($dhcpEnd),
                escapeshellarg($subnetMask),
                escapeshellarg($gateway),
                escapeshellarg(implode(',', $dnsServers)),
                escapeshellarg($webserverIp)
            );

            $output = [];
            $returnCode = 1;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                logMessage('Failed to update DHCP configuration: ' . implode(' | ', $output), 'ERROR');
                $result['error'] = 'Network settings were saved, but the DHCP configuration could not be updated. See the logs for details.';
            } else {
                logMessage('DHCP configuration updated successfully');
                $result['message'] = 'Network settings saved and the DHCP configuration was updated';
            }
            break;
            
        case 'save_global_config':
            // Save iLO settings
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update iLO settings - global configuration not found";
                break;
            }
            
            $scanStart = trim((string)($postData['ilo_scan_start'] ?? ''));
            $scanEnd = trim((string)($postData['ilo_scan_end'] ?? ''));

            if (!isValidIpv4($scanStart) || !isValidIpv4($scanEnd)) {
                $result['error'] = 'The iLO scan range must contain valid IPv4 addresses';
                break;
            }

            if (ip2long($scanStart) > ip2long($scanEnd)) {
                $result['error'] = 'The iLO scan range start must not be higher than the range end';
                break;
            }

            // Update iLO settings
            $globalConfig['ilo']['admin_user'] = trim((string)($postData['ilo_user'] ?? ''));
            $globalConfig['ilo']['scan_range_start'] = $scanStart;
            $globalConfig['ilo']['scan_range_end'] = $scanEnd;

            // An empty password field means "keep the current password";
            // browsers never repopulate password inputs, so treating blank as
            // "clear it" silently wiped the stored credential on every save.
            if (($postData['ilo_password'] ?? '') !== '') {
                $globalConfig['ilo']['admin_password'] = $postData['ilo_password'];
            }

            // Save the updated configuration
            if (saveJsonConfig($globalConfigPath, $globalConfig)) {
                $result['message'] = "iLO settings saved successfully";
            } else {
                $result['error'] = "Failed to save iLO settings";
            }
            break;
            
        case 'save_kickstart_templates':
            // Save kickstart template paths
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update kickstart templates - global configuration not found";
                break;
            }
            
            // Initialize kickstart_templates section if it doesn't exist
            if (!isset($globalConfig['deployment']['kickstart_templates'])) {
                $globalConfig['deployment']['kickstart_templates'] = [];
            }
            
            // Update template paths
            $globalConfig['deployment']['kickstart_templates']['standard'] = $postData['std_template'];
            $globalConfig['deployment']['kickstart_templates']['vcf'] = $postData['vcf_template'];
            
            // Update waiting template path if provided
            if (isset($postData['waiting_template'])) {
                $globalConfig['deployment']['waiting_template_path'] = $postData['waiting_template'];
            }
            
            // Validate paths
            if (!file_exists($postData['std_template'])) {
                logMessage("Warning: Standard kickstart template file does not exist: " . $postData['std_template'], 'WARNING');
            }
            
            if (!file_exists($postData['vcf_template'])) {
                logMessage("Warning: VCF kickstart template file does not exist: " . $postData['vcf_template'], 'WARNING');
            }
            
            // Save the updated configuration
            if (saveJsonConfig($globalConfigPath, $globalConfig)) {
                $result['message'] = "Kickstart template settings saved successfully";
            } else {
                $result['error'] = "Failed to save kickstart template settings";
            }
            break;
            
        case 'upload_esxi_image':
            // The four manual steps this replaces -- mount, copy, unmount, edit
            // global_config.json -- were unchecked, and a mistake in any of them
            // surfaced as a host that boots the installer and finds no modules.
            $upload = $_FILES['image'] ?? null;

            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                $result['error'] = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'The ISO exceeds the upload limit. Raise upload_max_filesize and post_max_size '
                        . 'in php.ini, and client_max_body_size in nginx.'
                    : 'The upload did not arrive (error code ' . $code . ')';
                break;
            }

            $install = imageInstall(
                $upload['tmp_name'],
                (string)($postData['version'] ?? ''),
                (string)($postData['description'] ?? ''),
                (string)($postData['sha256'] ?? '')
            );

            if ($install['success']) {
                $result['message'] = $install['message'];
            } else {
                $result['error'] = $install['error'];
            }
            break;

        case 'save_esxi_versions':
            // Save ESXi version settings
            $globalConfig = loadJsonConfig($globalConfigPath);
            if (!$globalConfig) {
                $result['error'] = "Cannot update ESXi version settings - global configuration not found";
                break;
            }
            
            // Handle removing a version if requested
            if (!empty($postData['remove_version'])) {
                $versionToRemove = $postData['remove_version'];
                if (isset($globalConfig['deployment']['esxi_versions'][$versionToRemove])) {
                    unset($globalConfig['deployment']['esxi_versions'][$versionToRemove]);
                    
                    // If we removed the default version, set a new default
                    if ($globalConfig['deployment']['default_version'] === $versionToRemove) {
                        // Set the first available version as default
                        $availableVersions = array_keys($globalConfig['deployment']['esxi_versions']);
                        if (!empty($availableVersions)) {
                            $globalConfig['deployment']['default_version'] = $availableVersions[0];
                        } else {
                            // No versions left, reset default
                            $globalConfig['deployment']['default_version'] = '';
                        }
                    }
                    
                    $result['message'] = "ESXi version '$versionToRemove' removed successfully";
                }
            }
            // Handle adding a new version
            elseif (isset($postData['add_new_version']) && !empty($postData['new_version'])) {
                $newVersion = $postData['new_version'];
                $newPath = $postData['new_version_path'];
                $newDesc = $postData['new_version_desc'];
                
                if (empty($newPath) || empty($newDesc)) {
                    $result['error'] = "Path and description are required for a new ESXi version";
                    break;
                }
                
                // Add the new version
                $globalConfig['deployment']['esxi_versions'][$newVersion] = [
                    'path' => $newPath,
                    'description' => $newDesc,
                    'bootloader_url' => "http://{$globalConfig['webserver']['ip']}/esxi/$newVersion/efi/boot/bootx64.efi"
                ];
                
                $result['message'] = "ESXi version '$newVersion' added successfully";
            }
            // Handle updating the default version
            elseif (isset($postData['save_version_settings']) && !empty($postData['default_version'])) {
                $defaultVersion = $postData['default_version'];
                
                // Ensure the selected version exists
                if (isset($globalConfig['deployment']['esxi_versions'][$defaultVersion])) {
                    $globalConfig['deployment']['default_version'] = $defaultVersion;
                    $result['message'] = "Default ESXi version updated to '$defaultVersion'";
                } else {
                    $result['error'] = "Selected default version '$defaultVersion' does not exist";
                    break;
                }
            }
            
            // Save the updated configuration
            if (saveJsonConfig($globalConfigPath, $globalConfig)) {
                if (empty($result['message'])) {
                    $result['message'] = "ESXi version settings saved successfully";
                }
            } else {
                $result['error'] = "Failed to save ESXi version settings";
            }
            break;
    
            // Add to processSettingsActions in settings.php
        case 'save_default_credentials':
            // Load existing credentials
            $credentials = storeLoadCredentials();
            if (!$credentials) {
                $credentials = [
                    'ilo' => ['hosts' => []],
                    'esxi' => ['hosts' => []]
                ];
            }
            
            // Ensure structure exists
            if (!isset($credentials['ilo'])) {
                $credentials['ilo'] = [];
            }
            
            if (!isset($credentials['esxi'])) {
                $credentials['esxi'] = [];
            }
            
            // Update default iLO credentials
            $credentials['ilo']['admin_user'] = trim((string)($postData['default_ilo_username'] ?? 'Administrator'));

            // Blank password fields mean "keep the current value".
            if (($postData['default_ilo_password'] ?? '') !== '') {
                $credentials['ilo']['admin_password'] = $postData['default_ilo_password'];
            }

            // Update default ESXi credentials
            if (($postData['default_esxi_password'] ?? '') !== '') {
                $credentials['esxi']['root_password'] = $postData['default_esxi_password'];
            }

            // Save updated credentials (atomically, mode 0640)
            $savedOk = storeSaveCredentials($credentials);

            if ($savedOk) {
                $result['message'] = "Default credentials updated successfully";
            } else {
                $result['error'] = "Failed to save default credentials";
            }
            break;

    }
    
    return $result;
}