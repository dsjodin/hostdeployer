<?php
/**
 * Simple authentication function
 */
function simple_authenticate() {
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
        header('WWW-Authenticate: Basic realm="ESXi Deployment Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Authentication required';
        return false;
    }
    
    // Simple hardcoded authentication
    if ($_SERVER['PHP_AUTH_USER'] !== 'admin' || $_SERVER['PHP_AUTH_PW'] !== 'password') {
        header('WWW-Authenticate: Basic realm="ESXi Deployment Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Invalid credentials';
        return false;
    }
    
    return true;
}