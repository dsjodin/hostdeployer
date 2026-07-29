<?php
/**
 * Logout endpoint for the ESXi Auto-deployment Admin.
 */

require_once __DIR__ . '/../lib/auth.php';

// logout() logs the event, clears the session (including its cookie) and
// redirects to the login page.
logout();
