<?php
/**
 * Logout Page for ESXi Auto-deployment Admin
 */

// Start session
session_start();

// Include authentication functions
require_once '/srv/autodeploy/lib/auth.php';

// Log the logout
if (isset($_SESSION['username'])) {
    auth_log("User {$_SESSION['username']} logged out", 'INFO');
}

// Clear session variables
session_unset();

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;