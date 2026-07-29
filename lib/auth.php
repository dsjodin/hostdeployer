<?php
/**
 * Authentication functions for ESXi Autodeploy Admin
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function to log messages specifically for authentication
function auth_log($message, $level = 'INFO') {
    $logFile = '/srv/autodeploy/logs/auth.php';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    // Make sure directory exists
    $dir = dirname($logFile);
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Check if user is authenticated via session
 * 
 * @param bool $logSuccess Whether to log successful authentications
 * @return bool|array False if authentication fails, user data if successful
 */
function authenticate($logSuccess = false) {
    // Check if session is active and authenticated
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        // Check for session timeout (30 minutes)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
            // Session expired
            auth_log("Session expired for user {$_SESSION['username']}", 'INFO');
            session_unset();
            session_destroy();
            redirectToLogin();
            return false;
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
        
        // Only log successful authentication if explicitly requested
        // (typically only needed for initial login, not subsequent page loads)
        if ($logSuccess) {
            auth_log("User {$_SESSION['username']} authenticated successfully", 'INFO');
        }
        
        // Return user data
        return [
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
    
    // Not authenticated via session, redirect to login page
    redirectToLogin();
    return false;
}

/**
 * Redirect to login page
 */
function redirectToLogin() {
    header('Location: login.php');
    exit;
}

/**
 * Logout user
 */
function logout() {
    // Log the logout
    if (isset($_SESSION['username'])) {
        auth_log("User {$_SESSION['username']} logged out", 'INFO');
    }
    
    // Clear session variables
    session_unset();
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    redirectToLogin();
}

/**
 * Check if user has required role
 * 
 * @param array $userData The user data returned by authenticate()
 * @param string $requiredRole The role to check for
 * @return bool True if user has required role, false otherwise
 */
function hasRole($userData, $requiredRole) {
    if (!$userData || !isset($userData['role'])) {
        return false;
    }
    
    if ($userData['role'] === 'admin') {
        // Admin role has access to everything
        return true;
    }
    
    return $userData['role'] === $requiredRole;
}

/**
 * Generate a password hash for adding to auth_config.php
 * 
 * @param string $password The plaintext password
 * @return string The password hash
 */
function generatePasswordHash($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Helper function to create a new password hash for console use
 * Usage: php -r "require('auth.php'); echo generatePasswordHash('your_new_password');"
 */
if (isset($argv) && basename($argv[0]) === 'auth.php') {
    if (isset($argv[1])) {
        echo generatePasswordHash($argv[1]) . PHP_EOL;
    } else {
        echo "Usage: php auth.php <password_to_hash>" . PHP_EOL;
    }
}