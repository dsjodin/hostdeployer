<?php
/**
 * Login Page for ESXi Auto-deployment Admin
 */

// Configure error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/srv/autodeploy/logs/php_errors.log');

// Start session
session_start();

// Check if user is already logged in
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    // Redirect to dashboard
    header('Location: admin_dashboard.php');
    exit;
}

// Include utility functions directly - don't include files that check for ADMIN_DASHBOARD
// Only include the auth.php file which doesn't have the protection
require_once '/srv/autodeploy/lib/auth.php';

// Process login form submission
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Path to auth config outside web root
    $authConfigPath = '/srv/autodeploy/config/auth_config.php';
    
    if (!file_exists($authConfigPath)) {
        auth_log("Auth config not found at $authConfigPath", 'ERROR');
        // Fall back to hardcoded credentials in development mode
        $authConfig = [
            'users' => [
                'admin' => [
                    'password_hash' => password_hash('password', PASSWORD_BCRYPT),
                    'role' => 'admin'
                ]
            ]
        ];
        auth_log("Using fallback credentials with username 'admin'", 'WARNING');
    } else {
        // Load auth configuration
        $authConfig = require($authConfigPath);
        
        if (!is_array($authConfig) || !isset($authConfig['users'])) {
            auth_log("Invalid auth configuration structure", 'ERROR');
            // Fall back to hardcoded credentials
            $authConfig = [
                'users' => [
                    'admin' => [
                        'password_hash' => password_hash('password', PASSWORD_BCRYPT),
                        'role' => 'admin'
                    ]
                ]
            ];
            auth_log("Using fallback credentials with username 'admin'", 'WARNING');
        }
    }
    
    // Check if user exists
    if (!isset($authConfig['users'][$username])) {
        auth_log("Authentication failed: Unknown user $username", 'WARNING');
        $error = 'Invalid username or password';
    } else {
        $userData = $authConfig['users'][$username];
        
        // Verify password
        if (!password_verify($password, $userData['password_hash'])) {
            auth_log("Authentication failed: Invalid password for $username", 'WARNING');
            $error = 'Invalid username or password';
        } else {
            // Authentication successful
            auth_log("User $username successfully authenticated", 'INFO');
            
            // Set session variables
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $userData['role'];
            $_SESSION['last_activity'] = time();
            
            // Redirect to dashboard
            header('Location: admin_dashboard.php');
            exit;
        }
    }
}


// Determine the base URL for assets
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host;

// Calculate the correct path to CSS
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESXi Auto-deployment Admin - Login</title>
    <!-- Bootstrap CSS (local file) -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <!-- Custom styles -->
    <link rel="stylesheet" href="css/admin-custom.css">
</head>
<body class="bg-light">
    <div class="login-page">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-5">
                    <div class="login-card bg-white shadow">
                        <div class="login-header">
                            <div class="login-brand">ESXi Auto-deployment</div>
                            <div class="text-white-50">Admin Dashboard</div>
                        </div>
                        
                        <div class="p-4 p-md-5">
                            <h4 class="text-center mb-4">Administrator Login</h4>
                            
                            <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php endif; ?>
                            
                            <form method="post">
                                <input type="hidden" name="action" value="login">
                                
                                <div class="form-floating mb-3">
                                    <input type="text" id="username" name="username" class="form-control" placeholder="Username" required autocomplete="username" autofocus>
                                    <label for="username">Username</label>
                                </div>
                                
                                <div class="form-floating mb-4">
                                    <input type="password" id="password" name="password" class="form-control" placeholder="Password" required autocomplete="current-password">
                                    <label for="password">Password</label>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">Login</button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="card-footer py-3 text-center text-muted">
                            ESXi Auto-deployment Administration
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS (local file) -->
    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>