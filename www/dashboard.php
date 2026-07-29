<?php
/**
 * ESXi Auto-deployment Admin - Dashboard Tab
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * Get the latest log entries from multiple log files
 * 
 * @param array $globalConfig Global configuration
 * @param int $entryCount Number of entries to return
 * @return array Array of log entries with timestamps
 */
function getRecentLogEntries($globalConfig, $entryCount = 15) {
    $logsDir = isset($globalConfig['paths']['logs_dir']) ? $globalConfig['paths']['logs_dir'] : '/srv/autodeploy/logs';
    
    // Log files to check, in order of priority
    $logFilesToCheck = [
        'admin_dashboard.log',
        'kickstart_generator.log',
        'ipxe_boot.log',
        'version_selector.log',
        'ilo_scanner.log',
        'secure_boot_manager.log'
    ];
    
    $allEntries = [];
    
    foreach ($logFilesToCheck as $logFile) {
        $logPath = "$logsDir/$logFile";
        
        if (!file_exists($logPath)) {
            continue;
        }
        
        // Get the last few lines from the log file
        $logContent = '';
        $fileSize = filesize($logPath);
        $maxReadSize = 20 * 1024; // Read at most 20KB
        
        if ($fileSize > $maxReadSize) {
            $handle = fopen($logPath, 'r');
            fseek($handle, -$maxReadSize, SEEK_END);
            $logContent = fread($handle, $maxReadSize);
            fclose($handle);
            
            // Find the first complete line
            $firstNewline = strpos($logContent, "\n");
            if ($firstNewline !== false) {
                $logContent = substr($logContent, $firstNewline + 1);
            }
        } else {
            $logContent = file_get_contents($logPath);
        }
        
        // Parse log entries
        $lines = explode("\n", $logContent);
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            // Parse the timestamp
            $timestamp = '';
            if (preg_match('/\[([\d-]+ [\d:]+)\]/', $line, $matches)) {
                $timestamp = $matches[1];
                // Convert to Unix timestamp for sorting
                $timestampUnix = strtotime($timestamp);
                if ($timestampUnix) {
                    $allEntries[] = [
                        'timestamp' => $timestampUnix,
                        'text' => $line,
                        'source' => $logFile
                    ];
                }
            }
        }
    }
    
    // Sort entries by timestamp (newest first)
    usort($allEntries, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Return only the requested number of entries
    return array_slice($allEntries, 0, $entryCount);
}

/**
 * Render the Dashboard Tab
 * 
 * @param array $globalConfig Global configuration
 * @param array $pendingHosts List of pending hosts
 * @param array $approvedHosts List of approved hosts
 * @param array $deployingHosts List of deploying hosts
 * @param array $deployedHosts List of deployed hosts
 */
function renderDashboardContent($globalConfig, $pendingHosts, $approvedHosts, $deployingHosts, $deployedHosts) {
    // Get recent log entries for the activity feed
    $recentLogs = getRecentLogEntries($globalConfig);
    ?>
    <div class="row">
        <div class="col-12">
            <h1 class="h3 mb-4 text-gray-800">Deployment Overview</h1>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary">System Status</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <td><strong>Web Server:</strong></td>
                                <td><?php echo htmlspecialchars($globalConfig['webserver']['url']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>DHCP Range:</strong></td>
                                <td><?php echo htmlspecialchars($globalConfig['network']['dhcp_range_start']); ?> - <?php echo htmlspecialchars($globalConfig['network']['dhcp_range_end']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Secure Boot:</strong></td>
                                <td>
                                    <?php if($globalConfig['security']['secure_boot_enabled']): ?>
                                        <span class="badge bg-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Auto-Registration:</strong></td>
                                <td>
                                    <?php if(isset($globalConfig['deployment']['auto_registration']['enabled']) && $globalConfig['deployment']['auto_registration']['enabled']): ?>
                                        <span class="badge bg-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Total Hosts:</strong></td>
                                <td><?php echo count($pendingHosts) + count($approvedHosts) + count($deployingHosts) + count($deployedHosts); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="m-0 font-weight-bold text-primary">Deployment Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-sm-6 mb-4">
                            <div class="status-box">
                                <span class="status-number"><?php echo count($pendingHosts); ?></span>
                                <span class="status-label">Pending</span>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-4">
                            <div class="status-box">
                                <span class="status-number"><?php echo count($approvedHosts); ?></span>
                                <span class="status-label">Approved</span>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-4">
                            <div class="status-box">
                                <span class="status-number"><?php echo count($deployingHosts); ?></span>
                                <span class="status-label">Deploying</span>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-4">
                            <div class="status-box">
                                <span class="status-number"><?php echo count($deployedHosts); ?></span>
                                <span class="status-label">Deployed</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary">System Activity Log</h5>
                    <div>
                        <button id="refresh-activity" class="btn btn-sm btn-primary">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <a href="?tab=settings" class="btn btn-sm btn-secondary">
                            <i class="fas fa-list"></i> View All Logs
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($recentLogs)): ?>
                        <p>No recent activity found. Check the logs in System Settings.</p>
                    <?php else: ?>
                        <div class="log-preview mb-0">
                            <?php foreach ($recentLogs as $entry): ?>
                                <?php
                                    // Format the log entry for display
                                    $formattedEntry = htmlspecialchars($entry['text']);
                                    
                                    // Highlight log levels
                                    $formattedEntry = preg_replace('/\[INFO\]/', '<span class="log-info">[INFO]</span>', $formattedEntry);
                                    $formattedEntry = preg_replace('/\[WARNING\]/', '<span class="log-warning">[WARNING]</span>', $formattedEntry);
                                    $formattedEntry = preg_replace('/\[ERROR\]/', '<span class="log-error">[ERROR]</span>', $formattedEntry);
                                    
                                    // Format timestamp
                                    $formattedEntry = preg_replace('/\[([\d-]+ [\d:]+)\]/', '[<span class="log-timestamp">$1</span>]', $formattedEntry);
                                    
                                    // Add source info
                                    $sourceInfo = ' <small class="text-muted">(' . htmlspecialchars($entry['source']) . ')</small>';
                                ?>
                                <div><?php echo $formattedEntry . $sourceInfo; ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Process dashboard-specific actions if needed
function processDashboardActions($action, $postData) {
    $result = [
        'message' => '',
        'error' => '',
    ];
    
    // Add dashboard-specific actions here if needed
    
    return $result;
}
