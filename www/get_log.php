<?php
/**
 * Log file viewer endpoint.
 *
 * Returns the tail of a log file as plain text for the admin dashboard.
 */

require_once __DIR__ . '/../lib/auth.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

// The old code gated access on the Referer header, which any client can set,
// and only then checked the session. Authentication is the actual control.
$user = currentUser();
if ($user === null) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Authentication required. Please log in to the admin dashboard first.');
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Resolve the requested log file
// ---------------------------------------------------------------------------

$file = (string)($_GET['file'] ?? '');

// Plain file name only: no directories, no traversal, must end in .log.
if ($file !== basename($file) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.log$/', $file)) {
    http_response_code(400);
    exit('Invalid log file name');
}

$globalConfig = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
$logsDir = $globalConfig['paths']['logs_dir'] ?? AUTODEPLOY_LOG_DIR;

$logPath = safePathJoin($logsDir, $file, true);

if ($logPath === null || !is_file($logPath) || !is_readable($logPath)) {
    http_response_code(404);
    exit('Log file not found or not readable');
}

$fileSize = filesize($logPath);

// ---------------------------------------------------------------------------
// Raw download
// ---------------------------------------------------------------------------

if (isset($_GET['download'])) {
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . $fileSize);
    readfile($logPath);
    exit;
}

// ---------------------------------------------------------------------------
// Tail, filter and return
// ---------------------------------------------------------------------------

$maxLines = (int)($_GET['lines'] ?? 100);
$maxLines = min(max($maxLines, 10), 1000);

$filter = (string)($_GET['filter'] ?? '');
$level = strtoupper((string)($_GET['level'] ?? ''));
if (!in_array($level, ['', 'INFO', 'WARNING', 'ERROR', 'DEBUG'], true)) {
    $level = '';
}

/**
 * @param int $bytes Size in bytes
 * @return string Human-readable size
 */
function formatSize($bytes) {
    return getReadableFileSize($bytes);
}

// Read at most the last 200 KB so a multi-megabyte log cannot exhaust memory.
$maxSize = 200 * 1024;

if ($fileSize > $maxSize) {
    $handle = fopen($logPath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit('Could not read the log file');
    }
    fseek($handle, -$maxSize, SEEK_END);
    $contents = (string)fread($handle, $maxSize);
    fclose($handle);

    // Drop the (probably partial) first line.
    $firstNewline = strpos($contents, "\n");
    if ($firstNewline !== false) {
        $contents = substr($contents, $firstNewline + 1);
    }
} else {
    $contents = (string)file_get_contents($logPath);
}

$lines = explode("\n", $contents);

if ($filter !== '' || $level !== '') {
    $lines = array_values(array_filter($lines, static function ($line) use ($filter, $level) {
        $matchesFilter = $filter === '' || stripos($line, $filter) !== false;
        $matchesLevel = $level === '' || stripos($line, "[$level]") !== false;
        return $matchesFilter && $matchesLevel;
    }));
}

if (count($lines) > $maxLines) {
    $lines = array_slice($lines, -$maxLines);
}

$header = '# File: ' . $file
    . ' | Size: ' . formatSize($fileSize)
    . ' | Last modified: ' . date('Y-m-d H:i:s', filemtime($logPath)) . "\n";
$header .= '# Showing last ' . count($lines) . ' lines'
    . ($filter !== '' ? " with filter: '$filter'" : '')
    . ($level !== '' ? " at level: $level" : '') . "\n";
$header .= '# ' . str_repeat('-', 88) . "\n";

echo $header . implode("\n", $lines);
