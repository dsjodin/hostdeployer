<?php
/**
 * ESXi Auto-deployment Admin - Template Manager actions
 *
 * The POST dispatcher and the download handler, split out of
 * www/templates.php so that rendering, the actions that change files, and the
 * helpers that touch the filesystem (now lib/templates.php) are three things
 * instead of one 1772-line file.
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/templates.php';

/**
 * Process template manager specific actions
 * 
 * @param string $action Action to perform
 * @param array $postData POST data
 * @param array $files Uploaded files ($_FILES)
 * @return array Result with message and error information
 */
/**
 * A note listing the tokens a template uses that nothing will substitute.
 *
 * A warning rather than a refusal: a template may legitimately be saved
 * half-finished, and an operator editing one is better served by being told
 * than by having the save rejected. What must not happen is the silence --
 * the literal {{TOKEN}} reaching a host, where it is a failed install with
 * nothing on the console explaining why.
 *
 * @param string $content Template contents
 * @return string Empty when every token is known
 */
function templateTokenWarning($content) {
    $unknown = templateUnknownTokens($content);
    if ($unknown === []) {
        return '';
    }

    return ' Warning: nothing will substitute {{' . implode('}}, {{', $unknown) . '}}.';
}

function processTemplatesActions($action, $postData, $files = []) {
    $result = [
        'message' => '',
        'error' => ''
    ];

    // Get templates directory
    $globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
    $templatesDir = getTemplatesDir($globalConfig);

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
            $templateFile = (string)($postData['template_file'] ?? '');
            $templatePath = resolveTemplatePath($templateFile, $templatesDir);

            if ($templatePath === null) {
                $result['error'] = 'Invalid template filename';
                break;
            }

            $content = $postData['template_content'] ?? '';
            $createBackup = isset($postData['create_backup']);

            if (saveTemplateFile($templatePath, $content, $createBackup)) {
                $result['message'] = "Template '$templateFile' saved successfully"
                    . templateTokenWarning((string)$content);
            } else {
                $result['error'] = "Failed to save template '$templateFile'";
            }
            break;

        case 'create_template':
            // Create new template
            if (empty($postData['template_name'])) {
                $result['error'] = "Missing template name";
                break;
            }

            $templateName = (string)$postData['template_name'];
            $templateType = $postData['template_type'] ?? 'custom';

            // Check if we should copy from existing template
            $content = '';
            if (isset($postData['use_existing_content'], $postData['existing_template'])) {
                $existingPath = resolveTemplatePath((string)$postData['existing_template'], $templatesDir);
                if ($existingPath !== null && is_file($existingPath)) {
                    $content = (string)file_get_contents($existingPath);
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
            $templateFile = (string)($postData['template_file'] ?? '');
            $templatePath = resolveTemplatePath($templateFile, $templatesDir);

            if ($templatePath === null || !is_file($templatePath)) {
                $result['error'] = 'Invalid template filename';
                break;
            }

            if (backupTemplateFile($templatePath)) {
                $result['message'] = "Backup created for template '$templateFile'";
            } else {
                $result['error'] = "Failed to create backup for template '$templateFile'";
            }
            break;

        case 'restore_backup':
            // Restore from backup
            $backupFile = (string)($postData['backup_file'] ?? '');
            $backupPath = resolveBackupPath($backupFile, $templatesDir);
            $originalFile = templateNameFromBackup($backupFile);

            if ($backupPath === null || $originalFile === null || !is_file($backupPath)) {
                $result['error'] = 'Invalid backup filename';
                break;
            }

            $originalPath = resolveTemplatePath($originalFile, $templatesDir);
            if ($originalPath === null) {
                $result['error'] = 'Invalid backup filename';
                break;
            }

            if (restoreTemplateFromBackup($backupPath, $originalPath)) {
                $result['message'] = "Template '$originalFile' restored successfully from backup";
            } else {
                $result['error'] = "Failed to restore template from backup";
            }
            break;

        case 'delete_template':
            // Delete template
            $templateFile = (string)($postData['template_file'] ?? '');
            $templatePath = resolveTemplatePath($templateFile, $templatesDir);

            if ($templatePath === null || !is_file($templatePath)) {
                $result['error'] = 'Invalid template filename';
                break;
            }

            // Check if template is in use. Compare canonical paths so that a
            // trailing slash or symlink in the config does not defeat the check.
            $inUse = false;
            $realTemplate = realpath($templatePath);
            $assignments = $globalConfig['deployment']['kickstart_templates'] ?? [];
            $assignments[] = $globalConfig['deployment']['waiting_template_path'] ?? '';

            foreach ($assignments as $path) {
                if ($path !== '' && realpath($path) === $realTemplate) {
                    $inUse = true;
                    break;
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
            $backupFile = (string)($postData['backup_file'] ?? '');
            $backupPath = resolveBackupPath($backupFile, $templatesDir);

            if ($backupPath === null || !is_file($backupPath)) {
                $result['error'] = 'Invalid backup filename';
                break;
            }

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
            if (!isset($files['template_file']) || ($files['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $result['error'] = 'Error uploading file (code '
                    . (int)($files['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) . ')';
                break;
            }

            $uploadedFile = $files['template_file'];

            // Refuse anything that is not actually an uploaded temp file.
            if (!is_uploaded_file($uploadedFile['tmp_name'])) {
                $result['error'] = 'Invalid upload';
                break;
            }

            // Kickstart files are small; reject oversized uploads outright.
            if (($uploadedFile['size'] ?? 0) > 512 * 1024) {
                $result['error'] = 'Template file is too large (limit 512 KB)';
                break;
            }

            $filename = basename((string)$uploadedFile['name']);
            if (!empty($postData['upload_filename'])) {
                $filename = basename((string)$postData['upload_filename']);
            }

            // Add .cfg extension if not present
            if (!preg_match('/\.cfg$/i', $filename)) {
                $filename .= '.cfg';
            }

            $targetPath = resolveTemplatePath($filename, $templatesDir);
            if ($targetPath === null) {
                $result['error'] = 'Invalid template filename. Use letters, digits, dot, dash and underscore only.';
                break;
            }

            // Check if file already exists
            if (file_exists($targetPath)) {
                // Create a backup of existing file
                backupTemplateFile($targetPath);
            }

            if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0640);
                // Read back rather than reading tmp_name: move_uploaded_file()
                // has already consumed it, and this is what actually landed.
                $result['message'] = "Template '$filename' uploaded successfully"
                    . templateTokenWarning((string)@file_get_contents($targetPath));
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

            // Assignments must point at real templates inside the templates
            // directory: generate_kickstart.php reads whatever path is stored
            // here and serves it verbatim to the installer.
            $assignmentFields = [
                'template_standard' => ['deployment', 'kickstart_templates', 'standard'],
                'template_vcf'      => ['deployment', 'kickstart_templates', 'vcf'],
                'template_waiting'  => ['deployment', 'waiting_template_path'],
            ];

            $assignmentError = '';
            foreach ($assignmentFields as $field => $configKeys) {
                if (!isset($postData[$field]) || $postData[$field] === '') {
                    continue;
                }

                // Accept either a bare file name or a full path inside the dir.
                $candidate = basename((string)$postData[$field]);
                $resolved = resolveTemplatePath($candidate, $templatesDir);

                if ($resolved === null || !is_file($resolved)) {
                    $assignmentError = "Invalid template selected for '$field'";
                    break;
                }

                if (count($configKeys) === 3) {
                    $globalConfig[$configKeys[0]][$configKeys[1]][$configKeys[2]] = $resolved;
                } else {
                    $globalConfig[$configKeys[0]][$configKeys[1]] = $resolved;
                }
            }

            if ($assignmentError !== '') {
                $result['error'] = $assignmentError;
                break;
            }

            // Save updated config
            $configPath = AUTODEPLOY_GLOBAL_CONFIG;
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
    $templateFile = (string)($postData['template_file'] ?? '');
    $templatePath = resolveTemplatePath($templateFile);

    if ($templatePath === null) {
        http_response_code(400);
        echo 'Invalid template filename';
        exit;
    }

    if (!is_file($templatePath)) {
        http_response_code(404);
        echo 'Template file not found';
        exit;
    }

    // The name is already restricted to [A-Za-z0-9._-]+.cfg, so it is safe to
    // place in the header without further quoting.
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $templateFile . '"');
    header('Content-Length: ' . filesize($templatePath));
    header('X-Content-Type-Options: nosniff');

    readfile($templatePath);
    exit;
}
?>
