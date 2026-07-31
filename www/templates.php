<?php
/**
 * ESXi Auto-deployment Admin - Template Manager, rendering
 *
 * This file used to be 1772 lines: path validation, filesystem operations, a
 * CSS block, two JavaScript blocks, the HTML, and the action dispatcher. The
 * parts had nothing to do with each other, and the one that needed reading
 * carefully -- the validation turning a POSTed name into a path -- was buried
 * at the top of the largest file in the tree.
 *
 * It is now three files and two assets:
 *
 *   lib/templates.php           validation, the filesystem, the token check
 *   www/templates_actions.php   the POST dispatcher and the download handler
 *   www/templates.php           this file: rendering, nothing else
 *   www/css/admin-custom.css    the editor styles
 *   www/js/template-editor.js   the editor behaviour
 *
 * Getting the <style> and <script> blocks out is a prerequisite for dropping
 * 'unsafe-inline' from the CSP (S14 in docs/SECURITY-REVIEW-2026-07.md), not
 * the whole of it: the variable buttons below still carry onclick handlers,
 * which need the same directive and are their own change.
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/templates.php';
require_once __DIR__ . '/templates_actions.php';

/**
 * Add HTML for the variables help modal
 */
function renderVariablesHelpModal() {
    ?>
    <!-- Variables Help Modal -->
    <div class="modal fade" id="variablesHelpModal" tabindex="-1" aria-labelledby="variablesHelpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="variablesHelpModalLabel">
                        <i class="fas fa-question-circle me-2"></i>Kickstart Template Variables
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>
                        The following variables can be used in your kickstart templates. They'll be replaced with actual values
                        when the kickstart file is generated during deployment.
                    </p>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead class="table-primary">
                                <tr>
                                    <th style="width: 25%">Variable</th>
                                    <th style="width: 40%">Description</th>
                                    <th style="width: 35%">Example Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>{{ROOT_PASSWORD_HASH}}</code></td>
                                    <td>Encrypted ESXi root password hash</td>
                                    <td><small class="text-muted">$6$abcdef12$X7Xsi2jGff...</small></td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXMGMT_IP}}</code></td>
                                    <td>Management IP address</td>
                                    <td>198.51.100.21</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXMGMT_NETMASK}}</code></td>
                                    <td>Management subnet mask</td>
                                    <td>255.255.255.0</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXMGMT_GATEWAY}}</code></td>
                                    <td>Management default gateway</td>
                                    <td>198.51.100.1</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXIMGMT_VLANID}}</code></td>
                                    <td>Management VLAN ID</td>
                                    <td>445</td>
                                </tr>
                                <tr>
                                    <td><code>{{DNS_SERVERS}}</code></td>
                                    <td>Comma-separated list of DNS servers</td>
                                    <td>192.0.2.53,192.0.2.54</td>
                                </tr>
                                <tr>
                                    <td><code>{{HOSTNAME}}</code></td>
                                    <td>ESXi host short name</td>
                                    <td>esxi-a48af0</td>
                                </tr>
                                <tr>
                                    <td><code>{{FQDN}}</code></td>
                                    <td>Fully qualified domain name</td>
                                    <td>esxi-a48af0.local</td>
                                </tr>
                                <tr>
                                    <td><code>{{NTP_SERVERS}}</code></td>
                                    <td>Comma-separated list of NTP servers</td>
                                    <td>time.google.com,pool.ntp.org</td>
                                </tr>
                                <tr>
                                    <td><code>{{SERVER_IP}}</code></td>
                                    <td>Deployment server IP address</td>
                                    <td>192.0.2.10</td>
                                </tr>
                                <tr>
                                    <td><code>{{MAC_ADDRESS}}</code></td>
                                    <td>MAC address of the ESXi host</td>
                                    <td>00:50:56:a4:8a:f0</td>
                                </tr>
                                <tr class="table-light">
                                    <th colspan="3">vMotion Variables (Standard Deployment Only)</th>
                                </tr>
                                <tr>
                                    <td><code>{{VMOTION_IP}}</code></td>
                                    <td>vMotion interface IP address</td>
                                    <td>198.51.100.21</td>
                                </tr>
                                <tr>
                                    <td><code>{{VMOTION_NETMASK}}</code></td>
                                    <td>vMotion interface subnet mask</td>
                                    <td>255.255.255.0</td>
                                </tr>
                                <tr>
                                    <td><code>{{VMOTION_VLANID}}</code></td>
                                    <td>vMotion interface VLAN ID</td>
                                    <td>10</td>
                                </tr>
                                <tr class="table-light">
                                    <th colspan="3">Conditional Blocks</th>
                                </tr>
                                <tr>
                                    <td colspan="3">
                                        <p>You can use conditional blocks to include content only when a condition is met:</p>
                                        <pre><code>{{#VMOTION_CONFIGURED}}
# This section only appears if vMotion is configured
esxcli network vswitch standard portgroup add --portgroup-name=vMotion --vswitch-name=vSwitch0
{{/VMOTION_CONFIGURED}}</code></pre>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render the Template Manager Tab
 * 
 * @param array $globalConfig Global configuration
 */
function renderTemplatesContent($globalConfig) {
    // Get templates directory from config
    $templatesDir = '/srv/autodeploy/templates';
    if (isset($globalConfig['paths']['templates_dir'])) {
        $templatesDir = $globalConfig['paths']['templates_dir'];
    }
    
    // Get all template files
    $templates = getTemplateFiles($templatesDir);
    
    // Get template file to edit (if specified)
    $editTemplate = null;
    $editContent = '';
    $backups = [];
    
    if (!empty($_GET['edit'])) {
        $editFile = (string)$_GET['edit'];
        $editPath = resolveTemplatePath($editFile, $templatesDir);

        if ($editPath !== null && is_file($editPath)) {
            $editTemplate = [
                'filename' => $editFile,
                'path' => $editPath
            ];

            $editContent = (string)file_get_contents($editPath);
            $backups = getTemplateBackups($editPath);
        }
    }

    // Get backup to view (if specified)
    $viewBackup = null;
    $backupContent = '';

    if (!empty($_GET['view_backup'])) {
        $backupFile = (string)$_GET['view_backup'];
        $backupPath = resolveBackupPath($backupFile, $templatesDir);

        if ($backupPath !== null && is_file($backupPath)) {
            $viewBackup = [
                'filename' => $backupFile,
                'path' => $backupPath
            ];

            $backupContent = (string)file_get_contents($backupPath);
        }
    }
    ?>
    <div class="row">
        <div class="col-12 mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 text-gray-800">Template Management</h1>
                <div>
                    <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#uploadTemplateModal">
                        <i class="fas fa-upload"></i> Upload Template
                    </button>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newTemplateModal">
                        <i class="fas fa-plus-circle"></i> Create New Template
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <?php if ($viewBackup): ?>
        <!-- Backup View -->
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary">
                        Viewing Backup: <?php echo h($viewBackup['filename']); ?>
                    </h5>
                    <div>
                        <a href="?tab=templates" class="btn btn-sm btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Templates
                        </a>
                        <form method="post" class="d-inline"><?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="backup_file" value="<?php echo h($viewBackup['filename']); ?>">
                            <button type="submit" class="btn btn-sm btn-warning" 
                                    onclick="return confirm('Are you sure you want to restore this backup? The current template will be overwritten.')">
                                <i class="fas fa-history"></i> Restore This Version
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            This is a read-only view of a backup version. To make changes, you can restore this version first.
                        </div>
                        <pre class="template-content"><?php echo h($backupContent); ?></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($editTemplate): ?>
<!-- Template Editor -->
<div class="col-12">
    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0 font-weight-bold text-primary">
                Editing Template: <?php echo h($editTemplate['filename']); ?>
            </h5>
            <div>
                <a href="?tab=templates" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Templates
                </a>
            </div>
        </div>
        <div class="card-body">
            <!-- Editor styles: css/admin-custom.css. Editor behaviour, including insertVariable(): js/template-editor.js. -->
            
            <form method="post"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="template_file" value="<?php echo h($editTemplate['filename']); ?>">
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label for="template-content" class="form-label mb-0">Template Content:</label>
                        <button type="button" class="btn btn-sm btn-info" id="showReferenceBtn">
                            <i class="fas fa-question-circle"></i> Variable Reference
                        </button>
                    </div>
                    <textarea class="form-control code-editor" id="template-content" name="template_content" 
                              rows="20"><?php echo h($editContent); ?></textarea>
                    
                    <!-- Variable Insertion Buttons -->
                    <div class="variable-section">
                        <p class="small mb-2">Click to insert a variable at cursor position:</p>
                        
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('ROOT_PASSWORD_HASH')">Root Password</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('ESXMGMT_IP')">Mgmt IP</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('ESXMGMT_NETMASK')">Mgmt Netmask</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('ESXMGMT_GATEWAY')">Mgmt Gateway</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('ESXIMGMT_VLANID')">Mgmt VLAN</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('DNS_SERVERS')">DNS Servers</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('HOSTNAME')">Hostname</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('FQDN')">FQDN</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('NTP_SERVERS')">NTP Servers</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('SERVER_IP')">Server IP</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" onclick="insertVariable('MAC_ADDRESS')">MAC Address</button>
                        </div>
                        
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-info variable-btn" onclick="insertVariable('VMOTION_IP')">vMotion IP</button>
                            <button type="button" class="btn btn-sm btn-outline-info variable-btn" onclick="insertVariable('VMOTION_NETMASK')">vMotion Netmask</button>
                            <button type="button" class="btn btn-sm btn-outline-info variable-btn" onclick="insertVariable('VMOTION_VLANID')">vMotion VLAN</button>
                            <button type="button" class="btn btn-sm btn-outline-warning variable-btn" onclick="insertVariableBlock('VMOTION_CONFIGURED')">vMotion Conditional Block</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="create_backup" name="create_backup" value="1" checked>
                    <label class="form-check-label" for="create_backup">
                        Create backup before saving
                    </label>
                </div>
                
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Template
                    </button>
                    <a href="?tab=templates" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
            
            <!-- Floating Variable Reference Window -->
            <div id="variableReferenceWindow" class="floating-reference-window">
                <div class="reference-window-header">
                    <h6 class="m-0">Kickstart Template Variables</h6>
                    <button type="button" class="btn-close" id="closeReferenceBtn" aria-label="Close"></button>
                </div>
                <div class="reference-window-body">
                    <table class="variable-table">
                        <thead>
                            <tr>
                                <th>Variable</th>
                                <th>Description</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>{{ROOT_PASSWORD_HASH}}</code></td>
                                <td>Encrypted root password hash</td>
                                <td>$6$abcdef12$X7Xsi2jGff...</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXMGMT_IP}}</code></td>
                                <td>Management IP address</td>
                                <td>198.51.100.21</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXMGMT_NETMASK}}</code></td>
                                <td>Management subnet mask</td>
                                <td>255.255.255.0</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXMGMT_GATEWAY}}</code></td>
                                <td>Management default gateway</td>
                                <td>198.51.100.1</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXIMGMT_VLANID}}</code></td>
                                <td>Management VLAN ID</td>
                                <td>445</td>
                            </tr>
                            <tr>
                                <td><code>{{DNS_SERVERS}}</code></td>
                                <td>Comma-separated list of DNS servers</td>
                                <td>192.0.2.53,192.0.2.54</td>
                            </tr>
                            <tr>
                                <td><code>{{HOSTNAME}}</code></td>
                                <td>ESXi host short name</td>
                                <td>esxi-a48af0</td>
                            </tr>
                            <tr>
                                <td><code>{{FQDN}}</code></td>
                                <td>Fully qualified domain name</td>
                                <td>esxi-a48af0.local</td>
                            </tr>
                            <tr>
                                <td><code>{{NTP_SERVERS}}</code></td>
                                <td>Comma-separated list of NTP servers</td>
                                <td>time.google.com,pool.ntp.org</td>
                            </tr>
                            <tr>
                                <td><code>{{SERVER_IP}}</code></td>
                                <td>Deployment server IP address</td>
                                <td>192.0.2.10</td>
                            </tr>
                            <tr>
                                <td><code>{{MAC_ADDRESS}}</code></td>
                                <td>MAC address of the ESXi host</td>
                                <td>00:50:56:a4:8a:f0</td>
                            </tr>
                            <tr>
                                <td><code>{{VMOTION_IP}}</code></td>
                                <td>vMotion interface IP address</td>
                                <td>198.51.100.21</td>
                            </tr>
                            <tr>
                                <td><code>{{VMOTION_NETMASK}}</code></td>
                                <td>vMotion interface subnet mask</td>
                                <td>255.255.255.0</td>
                            </tr>
                            <tr>
                                <td><code>{{VMOTION_VLANID}}</code></td>
                                <td>vMotion interface VLAN ID</td>
                                <td>10</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h6 class="mt-3">Conditional Blocks</h6>
                    <pre><code>{{#VMOTION_CONFIGURED}}
# This section only appears if vMotion is configured
esxcli network vswitch standard portgroup add --portgroup-name=vMotion --vswitch-name=vSwitch0
{{/VMOTION_CONFIGURED}}</code></pre>
                </div>
            </div>
            
            
            <?php if (!empty($backups)): ?>
            <div class="mt-4">
                <h5>Backup Versions</h5>
                <p>This template has <?php echo count($backups); ?> previous versions that you can restore if needed.</p>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Backup Date</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><?php echo h($backup['date_formatted']); ?></td>
                                <td><?php echo h($backup['size_formatted']); ?></td>
                                <td>
                                    <a href="?tab=templates&view_backup=<?php echo urlencode($backup['filename']); ?>" 
                                       class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <form method="post" class="d-inline"><?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="restore_backup">
                                        <input type="hidden" name="backup_file" value="<?php echo h($backup['filename']); ?>">
                                        <button type="submit" class="btn btn-sm btn-warning" 
                                                onclick="return confirm('Are you sure you want to restore this backup? Your current changes will be lost.')">
                                            <i class="fas fa-history"></i> Restore
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline"><?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="backup_file" value="<?php echo h($backup['filename']); ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" 
                                                onclick="return confirm('Are you sure you want to delete this backup? This cannot be undone.')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
        <?php else: ?>
        <!-- Templates List -->
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="m-0 font-weight-bold text-primary">Available Templates</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($templates)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> No template files found in directory: <?php echo h($templatesDir); ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Template Name</th>
                                    <th>Type</th>
                                    <th>Last Modified</th>
                                    <th>Size</th>
                                    <th>Backups</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><?php echo h($template['filename']); ?></td>
                                    <td><?php echo h($template['type']); ?></td>
                                    <td><?php echo h($template['modified_formatted']); ?></td>
                                    <td><?php echo h($template['size_formatted']); ?></td>
                                    <td>
                                        <?php if ($template['backup_count'] > 0): ?>
                                            <span class="badge bg-info"><?php echo $template['backup_count']; ?> versions</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?tab=templates&edit=<?php echo urlencode($template['filename']); ?>" 
                                               class="btn btn-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" class="d-inline"><?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="backup_template">
                                                <input type="hidden" name="template_file" value="<?php echo h($template['filename']); ?>">
                                                <button type="submit" class="btn btn-secondary">
                                                    <i class="fas fa-save"></i> Backup
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline"><?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="download_template">
                                                <input type="hidden" name="template_file" value="<?php echo h($template['filename']); ?>">
                                                <button type="submit" class="btn btn-info">
                                                    <i class="fas fa-download"></i> Download
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline"><?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_template">
                                                <input type="hidden" name="template_file" value="<?php echo h($template['filename']); ?>">
                                                <button type="submit" class="btn btn-danger" 
                                                        onclick="return confirm('Are you sure you want to delete this template? This cannot be undone.')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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
<?php if (isset($globalConfig['deployment']['kickstart_templates'])): ?>
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5 class="m-0 font-weight-bold text-primary">Current Template Assignments</h5>
                </div>
                <div class="card-body">
                    <p>These are the templates currently assigned in your deployment configuration:</p>
                    
                    <form method="post"><?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="update_template_assignments">
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Deployment Type</th>
                                        <th>Assigned Template</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Standard ESXi</td>
                                        <td>
                                            <select class="form-select" name="template_standard">
                                                <?php foreach ($templates as $template): ?>
                                                <option value="<?php echo h($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['kickstart_templates']['standard'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo h($template['filename']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>VMware Cloud Foundation (VCF)</td>
                                        <td>
                                            <select class="form-select" name="template_vcf">
                                                <?php foreach ($templates as $template): ?>
                                                <option value="<?php echo h($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['kickstart_templates']['vcf'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo h($template['filename']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Waiting for Approval</td>
                                        <td>
                                            <select class="form-select" name="template_waiting">
                                                <?php foreach ($templates as $template): ?>
                                                <option value="<?php echo h($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['waiting_template_path'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo h($template['filename']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Template Assignments
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- New Template Modal -->
    <div class="modal fade" id="newTemplateModal" tabindex="-1" aria-labelledby="newTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newTemplateModalLabel">Create New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post"><?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_template">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="new-template-name" class="form-label">Template Name:</label>
                            <input type="text" class="form-control" id="new-template-name" name="template_name" 
                                   required placeholder="e.g., kickstart_template_custom.cfg">
                            <div class="form-text">The .cfg extension will be added automatically if not provided.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new-template-type" class="form-label">Template Type:</label>
                            <select class="form-select" id="new-template-type" name="template_type">
                                <option value="standard">Standard ESXi</option>
                                <option value="vcf">VMware Cloud Foundation (VCF)</option>
                                <option value="waiting">Waiting for Approval</option>
                                <option value="custom">Custom (Empty)</option>
                            </select>
                            <div class="form-text">Selecting a type will pre-populate with a basic template structure.</div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="use-existing" name="use_existing_content">
                                <label class="form-check-label" for="use-existing">
                                    Copy content from an existing template
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="existing-template-select" style="display:none;">
                            <label for="existing-template" class="form-label">Copy from Template:</label>
                            <select class="form-select" id="existing-template" name="existing_template">
                                <?php foreach ($templates as $template): ?>
                                <option value="<?php echo h($template['filename']); ?>">
                                    <?php echo h($template['filename']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Upload Template Modal -->
    <div class="modal fade" id="uploadTemplateModal" tabindex="-1" aria-labelledby="uploadTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadTemplateModalLabel">Upload Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data"><?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="upload_template">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="template-file" class="form-label">Template File:</label>
                            <input type="file" class="form-control" id="template-file" name="template_file" required accept=".cfg,.txt">
                        </div>
                        
                        <div class="mb-3">
                            <label for="upload-filename" class="form-label">Save As (optional):</label>
                            <input type="text" class="form-control" id="upload-filename" name="upload_filename" 
                                   placeholder="Leave blank to use original filename">
                            <div class="form-text">The .cfg extension will be added automatically if not provided.</div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    
    <?php
    // Add the variables help modal at the end
    renderVariablesHelpModal();
}
