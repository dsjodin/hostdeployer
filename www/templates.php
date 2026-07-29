<?php
/**
 * ESXi Auto-deployment Admin - Template Manager
 * 
 * Provides a dedicated interface for managing kickstart templates
 * with editing, versioning, and backup capabilities
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * List all available template files in the templates directory
 * 
 * @param string $templatesDir Path to templates directory
 * @return array Array of template file information
 */
function getTemplateFiles($templatesDir) {
    $templates = [];
    
    if (is_dir($templatesDir)) {
        $files = glob("$templatesDir/*.cfg");
        
        foreach ($files as $file) {
            $filename = basename($file);
            $modTime = filemtime($file);
            $size = filesize($file);
            
            // Check if there are backup versions
            $backups = glob("$templatesDir/backups/$filename.*");
            
            // Get template type based on filename
            $type = 'Unknown';
            if (strpos($filename, 'std') !== false) {
                $type = 'Standard ESXi';
            } elseif (strpos($filename, 'vcf') !== false) {
                $type = 'VMware Cloud Foundation';
            } elseif (strpos($filename, 'waiting') !== false) {
                $type = 'Waiting for Approval';
            } else {
                // Try to determine type by content
                $content = file_get_contents($file);
                if (strpos($content, 'VMware Cloud Foundation') !== false) {
                    $type = 'VMware Cloud Foundation';
                } elseif (strpos($content, 'waiting for approval') !== false) {
                    $type = 'Waiting for Approval';
                } else {
                    $type = 'Standard ESXi';
                }
            }
            
            $templates[] = [
                'filename' => $filename,
                'path' => $file,
                'modified' => $modTime,
                'modified_formatted' => date('Y-m-d H:i:s', $modTime),
                'size' => $size,
                'size_formatted' => formatFileSize($size),
                'backup_count' => count($backups),
                'type' => $type
            ];
        }
        
        // Sort by modified time (newest first)
        usort($templates, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
    }
    
    return $templates;
}

/**
 * Format file size in human-readable format
 * 
 * @param int $bytes Size in bytes
 * @return string Formatted size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}
/**
 * Create a backup of a template file
 * 
 * @param string $filePath Path to the template file
 * @return bool True if successful, false otherwise
 */
function backupTemplateFile($filePath) {
    $backupDir = dirname($filePath) . '/backups';
    $filename = basename($filePath);
    
    // Create backup directory if it doesn't exist
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            return false;
        }
    }
    
    // Generate backup filename with timestamp
    $timestamp = date('Ymd_His');
    $backupPath = "$backupDir/{$filename}.{$timestamp}";
    
    return copy($filePath, $backupPath);
}

/**
 * Save template content to file
 * 
 * @param string $filePath Path to save the template
 * @param string $content Template content
 * @param bool $createBackup Whether to create a backup before saving
 * @return bool True if successful, false otherwise
 */
function saveTemplateFile($filePath, $content, $createBackup = true) {
    // Create a backup first if requested
    if ($createBackup && file_exists($filePath)) {
        if (!backupTemplateFile($filePath)) {
            dashboard_log("Failed to create backup for template: $filePath", 'WARNING');
            // Continue anyway
        }
    }
    
    // Save the new content
    $result = file_put_contents($filePath, $content);
    
    if ($result === false) {
        dashboard_log("Failed to save template file: $filePath", 'ERROR');
        return false;
    }
    
    dashboard_log("Template file saved successfully: $filePath", 'INFO');
    return true;
}

/**
 * Get all backup versions of a template
 * 
 * @param string $templatePath Path to the template file
 * @return array Array of backup information
 */
function getTemplateBackups($templatePath) {
    $backupDir = dirname($templatePath) . '/backups';
    $filename = basename($templatePath);
    $backups = [];
    
    if (is_dir($backupDir)) {
        $files = glob("$backupDir/$filename.*");
        
        foreach ($files as $file) {
            $backupFile = basename($file);
            $parts = explode('.', $backupFile);
            $timestamp = end($parts);
            
            // Try to parse the timestamp
            $dateFormatted = $timestamp;
            if (preg_match('/(\d{8})_(\d{6})/', $timestamp, $matches)) {
                $dateFormatted = substr($matches[1], 0, 4) . '-' . 
                                substr($matches[1], 4, 2) . '-' . 
                                substr($matches[1], 6, 2) . ' ' . 
                                substr($matches[2], 0, 2) . ':' . 
                                substr($matches[2], 2, 2) . ':' . 
                                substr($matches[2], 4, 2);
            }
            
            $backups[] = [
                'path' => $file,
                'filename' => $backupFile,
                'timestamp' => $timestamp,
                'date_formatted' => $dateFormatted,
                'size' => filesize($file),
                'size_formatted' => formatFileSize(filesize($file))
            ];
        }
        
        // Sort by timestamp (newest first)
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
    }
    
    return $backups;
}

/**
 * Create a new template file
 * 
 * @param string $templatesDir Templates directory
 * @param string $filename Template filename
 * @param string $content Template content
 * @param string $type Template type (standard, vcf, waiting)
 * @return bool True if successful, false otherwise
 */
function createTemplate($templatesDir, $filename, $content, $type) {
    // Ensure filename has .cfg extension
    if (!preg_match('/\.cfg$/', $filename)) {
        $filename .= '.cfg';
    }
    
    // Create a sample template based on type if content is empty
    if (empty($content)) {
        if ($type === 'standard') {
            $content = "# Standard ESXi Kickstart Template\n" .
                       "# Created: " . date('Y-m-d H:i:s') . "\n\n" .
                       "# Accept VMware EULA\n" .
                       "vmaccepteula\n\n" .
                       "# Set root password\n" .
                       "rootpw --iscrypted {{ROOT_PASSWORD_HASH}}\n\n" .
                       "# Install on first disk\n" .
                       "install --firstdisk --overwritevmfs\n\n" .
                       "# Configure networking\n" .
                       "network --bootproto=static --ip={{ESXMGMT_IP}} --netmask={{ESXMGMT_NETMASK}} " .
                       "--gateway={{ESXMGMT_GATEWAY}} --nameserver={{DNS_SERVERS}} --hostname={{HOSTNAME}}\n\n" .
                       "# Reboot after installation\n" .
                       "reboot\n\n" .
                       "%firstboot --interpreter=busybox\n" .
                       "# Add your firstboot commands here\n\n" .
                       "# Notify deployment completion\n" .
                       "wget -O /tmp/notify_deployment.py http://{{SERVER_IP}}/admin/notify_deployment.py\n" .
                       "python /tmp/notify_deployment.py --mac={{MAC_ADDRESS}} --server={{SERVER_IP}}\n";
        } elseif ($type === 'vcf') {
            $content = "# VMware Cloud Foundation (VCF) Kickstart Template\n" .
                       "# Created: " . date('Y-m-d H:i:s') . "\n\n" .
                       "# Accept VMware EULA\n" .
                       "vmaccepteula\n\n" .
                       "# Set root password\n" .
                       "rootpw --iscrypted {{ROOT_PASSWORD_HASH}}\n\n" .
                       "# Install on first disk\n" .
                       "install --firstdisk --overwritevmfs\n\n" .
                       "# Configure networking\n" .
                       "network --bootproto=static --ip={{ESXMGMT_IP}} --netmask={{ESXMGMT_NETMASK}} " .
                       "--gateway={{ESXMGMT_GATEWAY}} --vlanid={{ESXIMGMT_VLANID}} --nameserver={{DNS_SERVERS}} --hostname={{HOSTNAME}}\n\n" .
                       "# Reboot after installation\n" .
                       "reboot\n\n" .
                       "%firstboot --interpreter=busybox\n\n" .
                       "# Set FQDN\n" .
                       "esxcli system hostname set --fqdn={{FQDN}}\n\n" .
                       "# VCF-specific settings here\n\n" .
                       "# Notify deployment completion\n" .
                       "wget -O /tmp/notify_deployment.py http://{{SERVER_IP}}/admin/notify_deployment.py\n" .
                       "python /tmp/notify_deployment.py --mac={{MAC_ADDRESS}} --server={{SERVER_IP}}\n";
        } else {
            $content = "# Waiting Template\n" .
                       "# Created: " . date('Y-m-d H:i:s') . "\n\n" .
                       "# This template is shown to hosts waiting for approval\n\n" .
                       "# Accept VMware EULA\n" .
                       "vmaccepteula\n\n" .
                       "# Shutdown the installer\n" .
                       "%include /tmp/shutdown.cfg\n\n" .
                       "%pre --interpreter=busybox\n" .
                       "echo 'shutdown' > /tmp/shutdown.cfg\n\n" .
                       "echo 'Server with MAC {{MAC_ADDRESS}} is waiting for approval.'\n" .
                       "echo 'Please contact your administrator.'\n" .
                       "sleep 120\n";
        }
    }
    
    $filePath = "$templatesDir/$filename";
    
    // Don't overwrite existing files
    if (file_exists($filePath)) {
        return false;
    }
    
    return saveTemplateFile($filePath, $content, false);
}

/**
 * Restore a template from backup
 * 
 * @param string $backupPath Path to the backup file
 * @param string $targetPath Path to restore to
 * @return bool True if successful, false otherwise
 */
function restoreTemplateFromBackup($backupPath, $targetPath) {
    // Create a backup of the current file first
    if (file_exists($targetPath)) {
        if (!backupTemplateFile($targetPath)) {
            dashboard_log("Failed to create backup before restore: $targetPath", 'WARNING');
            // Continue anyway
        }
    }
    
    // Restore from backup
    if (!copy($backupPath, $targetPath)) {
        dashboard_log("Failed to restore template from backup: $backupPath", 'ERROR');
        return false;
    }
    
    dashboard_log("Template restored successfully from backup: $backupPath", 'INFO');
    return true;
}

/**
 * CSS to enhance the template editor
 */
function addTemplateEditorStyles() {
    ?>
    <style>
    /* Enhanced styles for the template editor */
    .template-editor-container {
        position: relative;
    }
    
    .code-editor {
        font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
        font-size: 0.9rem;
        line-height: 1.5;
        tab-size: 4;
        -moz-tab-size: 4;
        white-space: pre-wrap;
        resize: vertical;
        min-height: 400px;
    }
    
    /* Variable button styles */
    .variable-btn {
        margin: 2px;
        font-size: 0.8rem;
    }
    
    /* Variable section styles */
    .variable-section {
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
    }
    
    /* Reference table styles */
    .variable-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .variable-table th, .variable-table td {
        padding: 6px 10px;
        border: 1px solid #dee2e6;
    }
    
    .variable-table th {
        background-color: #e9ecef;
    }
    
    /* Floating reference window */
    .floating-reference-window {
        display: none;
        position: fixed;
        width: 700px;
        max-width: 90vw;
        max-height: 80vh;
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        z-index: 1050;
        overflow: hidden;
    }
    
    .reference-window-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
        background-color: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        cursor: move;
    }
    
    .reference-window-body {
        padding: 15px;
        overflow-y: auto;
        max-height: calc(80vh - 50px);
    }
    
    .reference-window-body code {
        background-color: #f1f1f1;
        padding: 2px 4px;
        border-radius: 3px;
        color: #d63384;
    }
    </style>
    <?php
}

/**
 * Add template editor enhancements
 */
function enhanceTemplateEditor() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Find the template editor area - using a more reliable selector
        const templateEditor = document.getElementById('template-content');
        if (!templateEditor) {
            console.error('Template editor not found!');
            return;
        }
        
        // Add help button to the label
        const textareaLabel = document.querySelector('label[for="template-content"]');
        if (textareaLabel) {
            // Add help button next to the label
            const helpButton = document.createElement('button');
            helpButton.type = 'button';
            helpButton.className = 'btn btn-sm btn-info ms-2';
            helpButton.innerHTML = '<i class="fas fa-question-circle"></i> Variables Help';
            helpButton.setAttribute('data-bs-toggle', 'modal');
            helpButton.setAttribute('data-bs-target', '#variablesHelpModal');
            
            textareaLabel.appendChild(helpButton);
        }
        
        // Add variable highlighting to the editor
        templateEditor.addEventListener('input', function() {
            // Simple implementation - just add a class
            this.classList.add('has-variables');
        });
        // Initial highlighting
        templateEditor.classList.add('has-variables');
        
        // Add quick insert dropdown for variables
        // Find the container - looking for the parent of the textarea
        const textareaContainer = templateEditor.closest('.mb-3');
        if (textareaContainer) {
            const insertDropdown = document.createElement('div');
            insertDropdown.className = 'dropdown mt-2';
            insertDropdown.innerHTML = `
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="insertVariableDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-plus-circle"></i> Insert Variable
                </button>
                <ul class="dropdown-menu" aria-labelledby="insertVariableDropdown">
                    <li><a class="dropdown-item" href="#" data-variable="ROOT_PASSWORD_HASH">Root Password Hash</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="ESXMGMT_IP">Management IP</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="ESXMGMT_NETMASK">Management Netmask</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="ESXMGMT_GATEWAY">Management Gateway</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="ESXIMGMT_VLANID">Management VLAN ID</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="DNS_SERVERS">DNS Servers</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="HOSTNAME">Hostname</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="FQDN">FQDN</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="NTP_SERVERS">NTP Servers</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="SERVER_IP">Server IP</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="MAC_ADDRESS">MAC Address</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" data-variable="VMOTION_IP">vMotion IP</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="VMOTION_NETMASK">vMotion Netmask</a></li>
                    <li><a class="dropdown-item" href="#" data-variable="VMOTION_VLANID">vMotion VLAN ID</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" data-variable-block="VMOTION_CONFIGURED">Conditional: vMotion Configured</a></li>
                </ul>
            `;
            
            textareaContainer.appendChild(insertDropdown);
            
            // Add event listeners for dropdown items
            const dropdownItems = insertDropdown.querySelectorAll('.dropdown-item');
            dropdownItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Dropdown item clicked:', this.textContent);
                    
                    const variable = this.getAttribute('data-variable');
                    const variableBlock = this.getAttribute('data-variable-block');
                    
                    if (variable) {
                        insertAtCursor(templateEditor, `{{${variable}}}`);
                    } else if (variableBlock) {
                        insertAtCursor(templateEditor, `{{#${variableBlock}}}\n# Your content here\n{{/${variableBlock}}}`);
                    }
                });
            });
        } else {
            console.error('Textarea container not found!');
        }
        
        // Function to insert text at cursor position
        function insertAtCursor(textarea, text) {
            const startPos = textarea.selectionStart;
            const endPos = textarea.selectionEnd;
            const scrollTop = textarea.scrollTop;
            
            textarea.value = textarea.value.substring(0, startPos) + text + textarea.value.substring(endPos, textarea.value.length);
            
            // Move cursor position to after inserted text
            textarea.selectionStart = startPos + text.length;
            textarea.selectionEnd = startPos + text.length;
            
            // Maintain scroll position
            textarea.scrollTop = scrollTop;
            
            // Focus the textarea
            textarea.focus();
        }
    });
    </script>
    <?php
}

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
                                    <td>192.168.1.21</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXMGMT_NETMASK}}</code></td>
                                    <td>Management subnet mask</td>
                                    <td>255.255.255.0</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXMGMT_GATEWAY}}</code></td>
                                    <td>Management default gateway</td>
                                    <td>192.168.1.1</td>
                                </tr>
                                <tr>
                                    <td><code>{{ESXIMGMT_VLANID}}</code></td>
                                    <td>Management VLAN ID</td>
                                    <td>445</td>
                                </tr>
                                <tr>
                                    <td><code>{{DNS_SERVERS}}</code></td>
                                    <td>Comma-separated list of DNS servers</td>
                                    <td>8.8.8.8,8.8.4.4</td>
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
                                    <td>10.1.40.151</td>
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
                                    <td>192.168.10.215</td>
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
    
    if (isset($_GET['edit']) && !empty($_GET['edit'])) {
        $editFile = $_GET['edit'];
        $editPath = "$templatesDir/$editFile";
        
        if (file_exists($editPath)) {
            $editTemplate = [
                'filename' => $editFile,
                'path' => $editPath
            ];
            
            $editContent = file_get_contents($editPath);
            $backups = getTemplateBackups($editPath);
        }
    }
    
    // Get backup to view (if specified)
    $viewBackup = null;
    $backupContent = '';
    
    if (isset($_GET['view_backup']) && !empty($_GET['view_backup'])) {
        $backupFile = $_GET['view_backup'];
        $backupPath = "$templatesDir/backups/$backupFile";
        
        if (file_exists($backupPath)) {
            $viewBackup = [
                'filename' => $backupFile,
                'path' => $backupPath
            ];
            
            $backupContent = file_get_contents($backupPath);
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
                        Viewing Backup: <?php echo htmlspecialchars($viewBackup['filename']); ?>
                    </h5>
                    <div>
                        <a href="?tab=templates" class="btn btn-sm btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Templates
                        </a>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($viewBackup['filename']); ?>">
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
                        <pre class="template-content"><?php echo htmlspecialchars($backupContent); ?></pre>
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
                Editing Template: <?php echo htmlspecialchars($editTemplate['filename']); ?>
            </h5>
            <div>
                <a href="?tab=templates" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Templates
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php addTemplateEditorStyles(); ?>
            
            <form method="post">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="template_file" value="<?php echo htmlspecialchars($editTemplate['filename']); ?>">
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label for="template-content" class="form-label mb-0">Template Content:</label>
                        <button type="button" class="btn btn-sm btn-info" id="showReferenceBtn">
                            <i class="fas fa-question-circle"></i> Variable Reference
                        </button>
                    </div>
                    <textarea class="form-control code-editor" id="template-content" name="template_content" 
                              rows="20"><?php echo htmlspecialchars($editContent); ?></textarea>
                    
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
                                <td>192.168.1.21</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXMGMT_NETMASK}}</code></td>
                                <td>Management subnet mask</td>
                                <td>255.255.255.0</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXMGMT_GATEWAY}}</code></td>
                                <td>Management default gateway</td>
                                <td>192.168.1.1</td>
                            </tr>
                            <tr>
                                <td><code>{{ESXIMGMT_VLANID}}</code></td>
                                <td>Management VLAN ID</td>
                                <td>445</td>
                            </tr>
                            <tr>
                                <td><code>{{DNS_SERVERS}}</code></td>
                                <td>Comma-separated list of DNS servers</td>
                                <td>8.8.8.8,8.8.4.4</td>
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
                                <td>10.1.40.151</td>
                            </tr>
                            <tr>
                                <td><code>{{MAC_ADDRESS}}</code></td>
                                <td>MAC address of the ESXi host</td>
                                <td>00:50:56:a4:8a:f0</td>
                            </tr>
                            <tr>
                                <td><code>{{VMOTION_IP}}</code></td>
                                <td>vMotion interface IP address</td>
                                <td>192.168.10.215</td>
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
            
            <script>
            // Function to insert a variable at cursor position
            function insertVariable(variable) {
                const textarea = document.getElementById('template-content');
                if (!textarea) return;
                
                const startPos = textarea.selectionStart;
                const endPos = textarea.selectionEnd;
                const scrollTop = textarea.scrollTop;
                
                // Insert the variable
                textarea.value = textarea.value.substring(0, startPos) + 
                                 '{{' + variable + '}}' + 
                                 textarea.value.substring(endPos);
                
                // Reset cursor position and focus
                textarea.selectionStart = startPos + variable.length + 4; // +4 for {{ and }}
                textarea.selectionEnd = startPos + variable.length + 4;
                textarea.scrollTop = scrollTop;
                textarea.focus();
            }
            
            // Function to insert a conditional block
            function insertVariableBlock(variable) {
                const textarea = document.getElementById('template-content');
                if (!textarea) return;
                
                const startPos = textarea.selectionStart;
                const endPos = textarea.selectionEnd;
                const scrollTop = textarea.scrollTop;
                
                // Create the block content
                const blockContent = '{{#' + variable + '}}\n# Your content here\n{{/' + variable + '}}';
                
                // Insert the block
                textarea.value = textarea.value.substring(0, startPos) + 
                                 blockContent + 
                                 textarea.value.substring(endPos);
                
                // Reset cursor position and focus
                textarea.selectionStart = startPos + blockContent.indexOf('# Your content') + 2;
                textarea.selectionEnd = startPos + blockContent.indexOf('# Your content') + 17;
                textarea.scrollTop = scrollTop;
                textarea.focus();
            }
            
            // Setup the reference window
            document.addEventListener('DOMContentLoaded', function() {
                const referenceWindow = document.getElementById('variableReferenceWindow');
                const showReferenceBtn = document.getElementById('showReferenceBtn');
                const closeReferenceBtn = document.getElementById('closeReferenceBtn');
                
                // Initial position from localStorage or default values
                let windowPos = {
                    left: localStorage.getItem('refWindowLeft') || '20px',
                    top: localStorage.getItem('refWindowTop') || '100px'
                };
                
                referenceWindow.style.left = windowPos.left;
                referenceWindow.style.top = windowPos.top;
                
                // Show the reference window
                showReferenceBtn.addEventListener('click', function() {
                    referenceWindow.style.display = 'block';
                });
                
                // Close the reference window
                closeReferenceBtn.addEventListener('click', function() {
                    referenceWindow.style.display = 'none';
                });
                
                // Make the window draggable
                let isDragging = false;
                let dragOffset = { x: 0, y: 0 };
                
                const header = referenceWindow.querySelector('.reference-window-header');
                
                header.addEventListener('mousedown', function(e) {
                    isDragging = true;
                    dragOffset.x = e.clientX - referenceWindow.getBoundingClientRect().left;
                    dragOffset.y = e.clientY - referenceWindow.getBoundingClientRect().top;
                    
                    // Prevent text selection during drag
                    e.preventDefault();
                });
                
                document.addEventListener('mousemove', function(e) {
                    if (!isDragging) return;
                    
                    const newLeft = e.clientX - dragOffset.x;
                    const newTop = e.clientY - dragOffset.y;
                    
                    // Keep the window within the viewport
                    const maxLeft = window.innerWidth - referenceWindow.offsetWidth;
                    const maxTop = window.innerHeight - referenceWindow.offsetHeight;
                    
                    referenceWindow.style.left = Math.max(0, Math.min(newLeft, maxLeft)) + 'px';
                    referenceWindow.style.top = Math.max(0, Math.min(newTop, maxTop)) + 'px';
                });
                
                document.addEventListener('mouseup', function() {
                    if (isDragging) {
                        isDragging = false;
                        
                        // Save position in localStorage
                        localStorage.setItem('refWindowLeft', referenceWindow.style.left);
                        localStorage.setItem('refWindowTop', referenceWindow.style.top);
                    }
                });
            });
            </script>
            
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
                                <td><?php echo htmlspecialchars($backup['date_formatted']); ?></td>
                                <td><?php echo htmlspecialchars($backup['size_formatted']); ?></td>
                                <td>
                                    <a href="?tab=templates&view_backup=<?php echo urlencode($backup['filename']); ?>" 
                                       class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="restore_backup">
                                        <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                        <button type="submit" class="btn btn-sm btn-warning" 
                                                onclick="return confirm('Are you sure you want to restore this backup? Your current changes will be lost.')">
                                            <i class="fas fa-history"></i> Restore
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup['filename']); ?>">
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
                        <i class="fas fa-info-circle me-2"></i> No template files found in directory: <?php echo htmlspecialchars($templatesDir); ?>
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
                                    <td><?php echo htmlspecialchars($template['filename']); ?></td>
                                    <td><?php echo htmlspecialchars($template['type']); ?></td>
                                    <td><?php echo htmlspecialchars($template['modified_formatted']); ?></td>
                                    <td><?php echo htmlspecialchars($template['size_formatted']); ?></td>
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
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="backup_template">
                                                <input type="hidden" name="template_file" value="<?php echo htmlspecialchars($template['filename']); ?>">
                                                <button type="submit" class="btn btn-secondary">
                                                    <i class="fas fa-save"></i> Backup
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="download_template">
                                                <input type="hidden" name="template_file" value="<?php echo htmlspecialchars($template['filename']); ?>">
                                                <button type="submit" class="btn btn-info">
                                                    <i class="fas fa-download"></i> Download
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="delete_template">
                                                <input type="hidden" name="template_file" value="<?php echo htmlspecialchars($template['filename']); ?>">
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
                    
                    <form method="post">
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
                                                <option value="<?php echo htmlspecialchars($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['kickstart_templates']['standard'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($template['filename']); ?>
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
                                                <option value="<?php echo htmlspecialchars($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['kickstart_templates']['vcf'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($template['filename']); ?>
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
                                                <option value="<?php echo htmlspecialchars($template['path']); ?>" 
                                                        <?php echo ($template['path'] === ($globalConfig['deployment']['waiting_template_path'] ?? '')) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($template['filename']); ?>
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
                <form method="post">
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
                                <option value="<?php echo htmlspecialchars($template['filename']); ?>">
                                    <?php echo htmlspecialchars($template['filename']); ?>
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
                <form method="post" enctype="multipart/form-data">
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
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle the existing template selector based on the checkbox
        const useExistingCheck = document.getElementById('use-existing');
        const existingTemplateSelect = document.getElementById('existing-template-select');
        
        if (useExistingCheck && existingTemplateSelect) {
            useExistingCheck.addEventListener('change', function() {
                existingTemplateSelect.style.display = this.checked ? 'block' : 'none';
            });
        }
    });
    </script>
    
    <?php
    // Add the variables help modal at the end
    renderVariablesHelpModal();
}

/**
 * Process template manager specific actions
 * 
 * @param string $action Action to perform
 * @param array $postData POST data
 * @param array $files Uploaded files ($_FILES)
 * @return array Result with message and error information
 */
function processTemplatesActions($action, $postData, $files = []) {
    $result = [
        'message' => '',
        'error' => ''
    ];
    
    // Get templates directory
    $globalConfig = loadJsonConfig('/srv/autodeploy/config/global_config.json');
    $templatesDir = '/srv/autodeploy/templates';
    if (isset($globalConfig['paths']['templates_dir'])) {
        $templatesDir = $globalConfig['paths']['templates_dir'];
    }
    
    // Create templates directory if it doesn't exist
    if (!is_dir($templatesDir)) {
        if (!mkdir($templatesDir, 0755, true)) {
            $result['error'] = "Failed to create templates directory: $templatesDir";
            return $result;
        }
    }
    
    // Process actions
    switch ($action) {
        case 'save_template':
            // Save edited template
            if (!isset($postData['template_file']) || empty($postData['template_file'])) {
                $result['error'] = "Missing template filename";
                break;
            }
            
            $templateFile = $postData['template_file'];
            $templatePath = "$templatesDir/$templateFile";
            $content = $postData['template_content'] ?? '';
            $createBackup = isset($postData['create_backup']);
            
            if (saveTemplateFile($templatePath, $content, $createBackup)) {
                $result['message'] = "Template '$templateFile' saved successfully";
            } else {
                $result['error'] = "Failed to save template '$templateFile'";
            }
            break;
            
        case 'create_template':
            // Create new template
            if (!isset($postData['template_name']) || empty($postData['template_name'])) {
                $result['error'] = "Missing template name";
                break;
            }
            
            $templateName = $postData['template_name'];
            $templateType = $postData['template_type'] ?? 'custom';
            
            // Check if we should copy from existing template
            $content = '';
            if (isset($postData['use_existing_content']) && isset($postData['existing_template'])) {
                $existingPath = "$templatesDir/" . $postData['existing_template'];
                if (file_exists($existingPath)) {
                    $content = file_get_contents($existingPath);
                }
            }
            
            if (createTemplate($templatesDir, $templateName, $content, $templateType)) {
                $result['message'] = "Template '$templateName' created successfully";
            } else {
                $result['error'] = "Failed to create template '$templateName'. It may already exist.";
            }
            break;
            
        case 'backup_template':
            // Create backup of template
            if (!isset($postData['template_file']) || empty($postData['template_file'])) {
                $result['error'] = "Missing template filename";
                break;
            }
            
            $templateFile = $postData['template_file'];
            $templatePath = "$templatesDir/$templateFile";
            
            if (backupTemplateFile($templatePath)) {
                $result['message'] = "Backup created for template '$templateFile'";
            } else {
                $result['error'] = "Failed to create backup for template '$templateFile'";
            }
            break;
            
        case 'restore_backup':
            // Restore from backup
            if (!isset($postData['backup_file']) || empty($postData['backup_file'])) {
                $result['error'] = "Missing backup filename";
                break;
            }
            
            $backupFile = $postData['backup_file'];
            $backupPath = "$templatesDir/backups/$backupFile";
            
            // Extract original filename from backup
            $parts = explode('.', $backupFile);
            array_pop($parts); // Remove the timestamp part
            $originalFile = implode('.', $parts);
            $originalPath = "$templatesDir/$originalFile";
            
            if (restoreTemplateFromBackup($backupPath, $originalPath)) {
                $result['message'] = "Template restored successfully from backup";
            } else {
                $result['error'] = "Failed to restore template from backup";
            }
            break;
            
        case 'delete_template':
            // Delete template
            if (!isset($postData['template_file']) || empty($postData['template_file'])) {
                $result['error'] = "Missing template filename";
                break;
            }
            
            $templateFile = $postData['template_file'];
            $templatePath = "$templatesDir/$templateFile";
            
            // Check if template is in use
            $inUse = false;
            if (isset($globalConfig['deployment']['kickstart_templates'])) {
                foreach ($globalConfig['deployment']['kickstart_templates'] as $type => $path) {
                    if ($path === $templatePath) {
                        $inUse = true;
                        break;
                    }
                }
            }
            
            if ($inUse) {
                $result['error'] = "Cannot delete template '$templateFile' because it is currently in use. Please update template assignments first.";
                break;
            }
            
            // Create a backup before deleting
            backupTemplateFile($templatePath);
            
            if (unlink($templatePath)) {
                $result['message'] = "Template '$templateFile' deleted successfully (a backup was created first)";
            } else {
                $result['error'] = "Failed to delete template '$templateFile'";
            }
            break;
            
        case 'delete_backup':
            // Delete backup
            if (!isset($postData['backup_file']) || empty($postData['backup_file'])) {
                $result['error'] = "Missing backup filename";
                break;
            }
            
            $backupFile = $postData['backup_file'];
            $backupPath = "$templatesDir/backups/$backupFile";
            
            if (unlink($backupPath)) {
                $result['message'] = "Backup '$backupFile' deleted successfully";
            } else {
                $result['error'] = "Failed to delete backup '$backupFile'";
            }
            break;
            
        case 'download_template':
            // Download template - handled separately in processDownloadRequest()
            break;
            
        case 'upload_template':
            // Upload template
            if (!isset($files['template_file']) || $files['template_file']['error'] != UPLOAD_ERR_OK) {
                $result['error'] = "Error uploading file: " . 
                                   ($files['template_file']['error'] ?? 'Unknown error');
                break;
            }
            
            $uploadedFile = $files['template_file'];
            $filename = basename($uploadedFile['name']);
            
            // Use custom filename if provided
            if (isset($postData['upload_filename']) && !empty($postData['upload_filename'])) {
                $filename = $postData['upload_filename'];
                
                // Add .cfg extension if not present
                if (!preg_match('/\.cfg$/', $filename)) {
                    $filename .= '.cfg';
                }
            }
            
            $targetPath = "$templatesDir/$filename";
            
            // Check if file already exists
            if (file_exists($targetPath)) {
                // Create a backup of existing file
                backupTemplateFile($targetPath);
            }
            
            if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                $result['message'] = "Template '$filename' uploaded successfully";
            } else {
                $result['error'] = "Failed to upload template";
            }
            break;
            
        case 'update_template_assignments':
            // Update template assignments in global config
            if (!$globalConfig) {
                $result['error'] = "Failed to load global configuration";
                break;
            }
            
            // Ensure kickstart_templates section exists
            if (!isset($globalConfig['deployment']['kickstart_templates'])) {
                $globalConfig['deployment']['kickstart_templates'] = [];
            }
            
            // Update standard template
            if (isset($postData['template_standard'])) {
                $globalConfig['deployment']['kickstart_templates']['standard'] = $postData['template_standard'];
            }
            
            // Update VCF template
            if (isset($postData['template_vcf'])) {
                $globalConfig['deployment']['kickstart_templates']['vcf'] = $postData['template_vcf'];
            }
            
            // Update waiting template
            if (isset($postData['template_waiting'])) {
                $globalConfig['deployment']['waiting_template_path'] = $postData['template_waiting'];
            }
            
            // Save updated config
            $configPath = '/srv/autodeploy/config/global_config.json';
            if (saveJsonConfig($configPath, $globalConfig)) {
                $result['message'] = "Template assignments updated successfully";
            } else {
                $result['error'] = "Failed to update template assignments";
            }
            break;
    }
    
    return $result;
}

/**
 * Process a download request for a template file
 * 
 * @param array $postData POST data with template information
 */
function processDownloadRequest($postData) {
    if (!isset($postData['template_file']) || empty($postData['template_file'])) {
        http_response_code(400);
        echo "Missing template filename";
        exit;
    }
    
    // Get templates directory
    $globalConfig = loadJsonConfig('/srv/autodeploy/config/global_config.json');
    $templatesDir = '/srv/autodeploy/templates';
    if (isset($globalConfig['paths']['templates_dir'])) {
        $templatesDir = $globalConfig['paths']['templates_dir'];
    }
    
    $templateFile = $postData['template_file'];
    $templatePath = "$templatesDir/$templateFile";
    
    if (!file_exists($templatePath)) {
        http_response_code(404);
        echo "Template file not found";
        exit;
    }
    
    // Set headers for download
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $templateFile . '"');
    header('Content-Length: ' . filesize($templatePath));
    
    // Send file content
    readfile($templatePath);
    exit;
}
?>