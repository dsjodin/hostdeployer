<?php
/**
 * Enhanced log file viewer script
 * Retrieves the content of log files for display in the admin interface
 */

// Start session to check for existing authentication
session_start();

// Basic security - restrict access to this script
if (!isset($_SERVER['HTTP_REFERER']) || 
    !str_contains($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied');
}

// Check for session-based authentication
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('HTTP/1.0 401 Unauthorized');
    die('Authentication required. Please log in to the admin dashboard first.');
}

// Validate the requested log file
$file = $_GET['file'] ?? '';
if (empty($file) || !preg_match('/^[a-zA-Z0-9_\.-]+\.log$/', $file)) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid log file name');
}

// Load global config to get logs directory
$globalConfigPath = '/srv/autodeploy/config/global_config.json';
$globalConfig = null;

if (file_exists($globalConfigPath)) {
    $globalConfig = json_decode(file_get_contents($globalConfigPath), true);
}

$logsDir = $globalConfig['paths']['logs_dir'] ?? '/srv/autodeploy/logs';
$logPath = $logsDir . '/' . $file;

// Check if the log file exists and is readable
if (!file_exists($logPath) || !is_readable($logPath)) {
    header('HTTP/1.0 404 Not Found');
    die('Log file not found or not readable');
}

// Default max lines - can be set in query parameters
$maxLines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
$maxLines = min(max($maxLines, 10), 1000); // Limit between 10 and 1000 lines

// Filter options
$filter = $_GET['filter'] ?? '';
$level = $_GET['level'] ?? ''; // INFO, WARNING, ERROR or empty for all

// Get file size for information
$fileSize = filesize($logPath);

// Convert size to human-readable format
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Handle the raw file download option
if (isset($_GET['download'])) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . basename($logPath) . '"');
    header('Content-Length: ' . $fileSize);
    readfile($logPath);
    exit;
}

// Get the most recent entries (based on lines or max size)
$maxSize = 200 * 1024; // 200KB for web display
$contents = '';

if ($fileSize > $maxSize) {
    // For large files, read only the last part
    $handle = fopen($logPath, 'r');
    fseek($handle, -$maxSize, SEEK_END);
    $contents = fread($handle, $maxSize);
    fclose($handle);
    
    // Find the first complete line
    $firstNewline = strpos($contents, "\n");
    if ($firstNewline !== false) {
        $contents = substr($contents, $firstNewline + 1);
    }
} else {
    // For small files, read the entire file
    $contents = file_get_contents($logPath);
}

// Split into lines
$lines = explode("\n", $contents);

// Apply filtering if requested
if (!empty($filter) || !empty($level)) {
    $filteredLines = [];
    foreach ($lines as $line) {
        $matchesFilter = empty($filter) || stripos($line, $filter) !== false;
        $matchesLevel = empty($level) || stripos($line, "[$level]") !== false;
        
        if ($matchesFilter && $matchesLevel) {
            $filteredLines[] = $line;
        }
    }
    $lines = $filteredLines;
}

// Limit to the requested number of lines
if (count($lines) > $maxLines) {
    $lines = array_slice($lines, -$maxLines);
}

// Combine the lines back together
$contents = implode("\n", $lines);

// Add file info at the top of the output
$fileInfo = "# File: $file | Size: " . formatSize($fileSize) . " | Last modified: " . date("Y-m-d H:i:s", filemtime($logPath)) . "\n";
$fileInfo .= "# Showing last " . count($lines) . " lines" . (!empty($filter) ? " with filter: '$filter'" : "") . (!empty($level) ? " at level: $level" : "") . "\n";
$fileInfo .= "# ------------------------------------------------------------------------------------------\n";

// Output the log content
header('Content-Type: text/plain');
echo $fileInfo . $contents;