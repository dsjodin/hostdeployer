<?php
/**
 * What a template may refer to, and what it does refer to.
 *
 * CI checked that every {{TOKEN}} in templates/*.cfg is one the generator
 * supplies -- the right check in the wrong place, because templates are edited
 * and uploaded through the admin UI and never go near CI. A template saved
 * with {{SERVER_URI}} in it was accepted without a word and reached the
 * installer with the literal still there, which is a failed install with
 * nothing on the console to explain it.
 *
 * So the check lives here, the UI warns with it on every save and upload, and
 * CI calls the same code rather than approximating it in shell.
 *
 * Nothing here touches the filesystem or the request. The variable maps are
 * built from values the caller has already gathered, which is what makes both
 * the maps and the check testable.
 *
 * The second half of the file is the template helpers that were buried in
 * www/templates.php (C10 in docs/CODE-REVIEW-2026-07.md). Those do touch the
 * filesystem -- that is what they are for.
 */

require_once __DIR__ . '/utils.php';

// ---------------------------------------------------------------------------
// Template files on disk
// ---------------------------------------------------------------------------
//
// Moved out of www/templates.php, which was 1772 lines of path validation,
// filesystem operations, CSS, JavaScript, HTML and an action dispatcher with
// nothing to do with one another. The validation is the part that has to be
// read carefully -- a template name arrives from POST and becomes a path --
// and it was buried at the top of the largest file in the tree.

if (!function_exists('templateLog')) {
    /**
     * Record what happened to a template file.
     *
     * These functions used to call dashboard_log(), which lives in
     * www/admin_dashboard.php -- so moving them here would have made lib/
     * depend on a symbol defined in www/, and every template save fatal
     * outside the dashboard. Same destination, no dependency: an operator
     * looking for "who overwrote the kickstart" still finds it in
     * admin_dashboard.log.
     *
     * @param string $message Message to log
     * @param string $level   Log level
     */
    function templateLog($message, $level = 'INFO') {
        logMessage($message, $level, AUTODEPLOY_LOG_DIR . '/admin_dashboard.log');
    }
}

/**
 * Resolve the configured templates directory.
 *
 * @param array|null $globalConfig Global configuration
 * @return string Absolute path to the templates directory
 */
function getTemplatesDir($globalConfig = null) {
    if ($globalConfig === null) {
        $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
    }

    $dir = $globalConfig['paths']['templates_dir'] ?? (AUTODEPLOY_ROOT . '/templates');

    return rtrim($dir, '/');
}

/**
 * Validate a user-supplied template file name.
 *
 * Template names come straight from POST/GET data. Without this check a
 * logged-in user could read, overwrite or delete any file the web server can
 * reach (for example ../config/credentials.json, or a new .php file inside
 * the document root), which is a remote code execution path.
 *
 * @param string $filename Untrusted file name
 * @return bool True when the name is a plain *.cfg file name
 */
function isValidTemplateName($filename) {
    $filename = (string)$filename;

    // \z, not $. PCRE's $ also matches immediately before a trailing newline,
    // so "template.cfg\n" -- which a request can carry and basename() leaves
    // alone -- passed this check and became a filename with a newline in it.
    return $filename !== ''
        && $filename === basename($filename)
        && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.cfg\z/', $filename);
}

/**
 * Validate a user-supplied backup file name (template.cfg.YYYYmmdd_HHMMSS).
 *
 * @param string $filename Untrusted file name
 * @return bool True when the name is a valid backup file name
 */
function isValidBackupName($filename) {
    $filename = (string)$filename;

    if ($filename === '' || $filename !== basename($filename)) {
        return false;
    }

    // \z rather than $, for the reason isValidTemplateName() gives.
    if (!preg_match('/^(.+\.cfg)\.\d{8}_\d{6}\z/', $filename, $matches)) {
        return false;
    }

    return isValidTemplateName($matches[1]);
}

/**
 * Resolve a template file name to an absolute path inside the templates dir.
 *
 * @param string      $filename     Untrusted template file name
 * @param string|null $templatesDir Templates directory (defaults to config)
 * @return string|null Absolute path, or null when the name is not acceptable
 */
function resolveTemplatePath($filename, $templatesDir = null) {
    if (!isValidTemplateName($filename)) {
        return null;
    }

    return ($templatesDir ?? getTemplatesDir()) . '/' . $filename;
}

/**
 * Resolve a backup file name to an absolute path inside the backups dir.
 *
 * @param string      $filename     Untrusted backup file name
 * @param string|null $templatesDir Templates directory (defaults to config)
 * @return string|null Absolute path, or null when the name is not acceptable
 */
function resolveBackupPath($filename, $templatesDir = null) {
    if (!isValidBackupName($filename)) {
        return null;
    }

    return ($templatesDir ?? getTemplatesDir()) . '/backups/' . $filename;
}

/**
 * Derive the original template name from a backup file name.
 *
 * @param string $backupFile Backup file name
 * @return string|null Original template file name, or null
 */
function templateNameFromBackup($backupFile) {
    if (!isValidBackupName($backupFile)) {
        return null;
    }

    return preg_replace('/\.\d{8}_\d{6}$/', '', $backupFile);
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
        $files = glob("$templatesDir/*.cfg") ?: [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            // Both return false if the file went away between the glob and
            // the stat. Treat that as zero rather than letting false reach
            // date() and getReadableFileSize().
            $modTime = filemtime($file) ?: 0;
            $size = filesize($file) ?: 0;
            
            // Check if there are backup versions
            $backups = glob("$templatesDir/backups/$filename.*") ?: [];
            
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
                'size_formatted' => getReadableFileSize($size),
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
    
    // One-second granularity, which means two backups in the same second want
    // the same name. Keep the one already there rather than overwriting it:
    // it holds the older content, which is the whole point of a backup, and
    // overwriting made restoreTemplateFromBackup() destructive -- it backs up
    // the current file before restoring, and in the same second that landed on
    // top of the very backup it was about to restore from, so the restore
    // silently put the broken content back.
    $timestamp = date('Ymd_His');
    $backupPath = "$backupDir/{$filename}.{$timestamp}";

    if (file_exists($backupPath)) {
        return true;
    }

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
            templateLog("Failed to create backup for template: $filePath", 'WARNING');
            // Continue anyway
        }
    }
    
    // Save the new content
    $result = file_put_contents($filePath, $content);
    
    if ($result === false) {
        templateLog("Failed to save template file: $filePath", 'ERROR');
        return false;
    }
    
    templateLog("Template file saved successfully: $filePath", 'INFO');
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
        $files = glob("$backupDir/$filename.*") ?: [];
        
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
                'size_formatted' => getReadableFileSize(filesize($file))
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
    
    $filePath = resolveTemplatePath($filename, $templatesDir);
    if ($filePath === null) {
        templateLog("Rejected invalid template name: $filename", 'WARNING');
        return false;
    }

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
            templateLog("Failed to create backup before restore: $targetPath", 'WARNING');
            // Continue anyway
        }
    }
    
    // Restore from backup
    if (!copy($backupPath, $targetPath)) {
        templateLog("Failed to restore template from backup: $backupPath", 'ERROR');
        return false;
    }
    
    templateLog("Template restored successfully from backup: $backupPath", 'INFO');
    return true;
}

if (!function_exists('kickstartVariables')) {
    /**
     * The substitutions a kickstart template is rendered with.
     *
     * @param array<string, mixed> $host             Host record
     * @param array<string, mixed> $globalConfig     Global configuration
     * @param string               $rootPasswordHash Hashed ESXi root password
     * @param string               $bootToken        Token proving this host was told to boot
     * @param string               $deploymentType   'standard' or 'vcf'
     * @return array<string, mixed> Token name => value
     */
    function kickstartVariables(
        array $host,
        array $globalConfig,
        $rootPasswordHash,
        $bootToken,
        $deploymentType
    ) {
        $serverIp = $globalConfig['webserver']['ip'] ?? '';

        $variables = [
            'ROOT_PASSWORD_HASH' => $rootPasswordHash,
            'ESXMGMT_IP'         => $host['management_ip'] ?? '',
            'ESXMGMT_NETMASK'    => $host['management_netmask'] ?? '255.255.255.0',
            'ESXMGMT_GATEWAY'    => $host['management_gateway'] ?? '',
            'ESXIMGMT_VLANID'    => (int)($host['vlans']['management'] ?? 0),
            'DNS_SERVERS'        => implode(',', (array)($globalConfig['network']['dns_servers'] ?? [])),
            'NTP_SERVERS'        => implode(',', (array)($globalConfig['network']['ntp_servers'] ?? [])),
            'HOSTNAME'           => $host['hostname'] ?? '',
            'FQDN'               => ($host['fqdn'] ?? '') ?: (($host['hostname'] ?? 'esxi') . '.local'),
            'SERVER_IP'          => $serverIp,
            'SERVER_URL'         => rtrim((string)($globalConfig['webserver']['url'] ?? "http://$serverIp"), '/'),
            'MAC_ADDRESS'        => $host['mac_address'] ?? '',
            'DATASTORE_NAME'     => $host['datastore']['name'] ?? 'datastore1',
            // Carried into %firstboot so the progress beacon and the completion
            // callback can present it too. Those endpoints write to the
            // inventory, and until the token existed anything on the network
            // could call them: marking a host deployed stops its installation,
            // and the operator sees a host that hung rather than one that was
            // interfered with.
            'BOOT_TOKEN'         => $bootToken,
        ];

        // vMotion is only rendered when the host actually has an address for
        // it. VCF configures its own during bring-up.
        $vmotionIp = $host['vmotion_ip'] ?? '';
        if ($deploymentType === 'standard' && $vmotionIp !== '') {
            $variables['VMOTION_CONFIGURED'] = true;
            $variables['VMOTION_IP']         = $vmotionIp;
            $variables['VMOTION_NETMASK']    = $host['vmotion_netmask'] ?? '255.255.255.0';
            $variables['VMOTION_VLANID']     = (int)($host['vlans']['vmotion'] ?? 0);
        } else {
            $variables['VMOTION_CONFIGURED'] = false;
        }

        return $variables;
    }
}

if (!function_exists('waitingTemplateVariables')) {
    /**
     * The substitutions the waiting template is rendered with.
     *
     * A host awaiting approval gets this instead of a kickstart, so that the
     * installer idles rather than erroring out. It holds nothing worth
     * protecting and has its own, much smaller, set of tokens.
     *
     * @param array<string, mixed> $host         Host record
     * @param array<string, mixed> $globalConfig Global configuration
     * @return array<string, mixed> Token name => value
     */
    function waitingTemplateVariables(array $host, array $globalConfig) {
        return [
            'MAC_ADDRESS'     => $host['mac_address'] ?? '',
            'REGISTERED_TIME' => $host['registered_time'] ?? date('Y-m-d H:i:s'),
            'SERVER_IP'       => $globalConfig['webserver']['ip'] ?? '',
        ];
    }
}

if (!function_exists('templateVariableNames')) {
    /**
     * Every token name the application can substitute.
     *
     * Derived by asking the two variable builders rather than maintained by
     * hand, so the list cannot drift from what is actually set -- a hand-kept
     * copy would go stale exactly when a new token is added, which is the one
     * moment the check matters. Both sides of the vMotion condition are asked,
     * because a template may legitimately use either.
     *
     * The kickstart and waiting sets are merged rather than kept apart: which
     * template is which is decided by global_config.json, an uploaded file can
     * be given any name, and this list backs a warning rather than a refusal.
     *
     * @return string[]
     */
    function templateVariableNames() {
        $withVmotion = kickstartVariables(
            ['vmotion_ip' => '10.0.0.1'],
            [],
            '',
            '',
            'standard'
        );

        return array_values(array_unique(array_merge(
            array_keys($withVmotion),
            array_keys(kickstartVariables([], [], '', '', 'vcf')),
            array_keys(waitingTemplateVariables([], []))
        )));
    }
}

if (!function_exists('templateUnknownTokens')) {
    /**
     * The tokens a template uses that nothing will ever substitute.
     *
     * renderTemplate() leaves an unknown {{TOKEN}} alone rather than blanking
     * it, so a typo is visible instead of silently producing an empty value --
     * visible in the rendered kickstart, that is, on a host that is by then
     * mid-install. This is how it becomes visible at the point it was typed.
     *
     * Names used by {{IF NAME}} are checked too. processConditionals()
     * evaluates an unknown name as falsy, so a misspelled condition does not
     * fail: it quietly renders the wrong branch.
     *
     * @param string $content Template contents
     * @return string[] Token names, in the order they first appear
     */
    function templateUnknownTokens($content) {
        // Comment lines are excluded: the templates carry a header documenting
        // their own {{TOKEN}} syntax, and that is documentation, not a
        // reference to a variable.
        $stripped = (string)preg_replace('/^[ \t]*#.*$/m', '', (string)$content);

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $stripped, $plain);
        preg_match_all('/\{\{IF\s+([A-Z0-9_]+)\}\}/', $stripped, $conditions);

        $used = array_merge($plain[1], $conditions[1]);

        $known = array_merge(templateVariableNames(), [
            // Control directives handled by processConditionals(), not values.
            'IF', 'ELSE', 'ENDIF',
        ]);

        return array_values(array_unique(array_diff($used, $known)));
    }
}
