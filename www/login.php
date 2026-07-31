<?php
/**
 * Login Page for ESXi Auto-deployment Admin
 */

require_once __DIR__ . '/../lib/auth.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AUTODEPLOY_LOG_DIR . '/php_errors.log');

// This page reads $_SESSION directly rather than through the auth helpers,
// so it has to open the session itself.
startAdminSession();

// Already logged in?
if (!empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: admin_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Counted in the database, keyed by username and by source address. The
    // counter used to be in $_SESSION, where a client that simply did not send
    // the cookie back got a fresh one on every attempt -- so it throttled
    // browsers and nothing else. See lib/auth.php.
    $throttle = authThrottleStatus($username, $clientIp);

    // The form has always rendered csrfField() and nothing ever checked what
    // came back. A login form that does not verify its token can be submitted
    // from any page the operator happens to visit, which signs their browser
    // into an account the attacker controls -- and everything approved,
    // uploaded or typed afterwards happens inside a session someone else
    // opened. The dashboard has verified this since it was written; the login
    // page was the one POST in the tree that did not.
    if (!verifyCsrfToken($_POST)) {
        auth_log("Login attempt with a missing or invalid CSRF token from $clientIp", 'WARNING');
        $error = 'Your session could not be verified. Please try again.';
    } elseif ($throttle['wait'] > 0) {
        auth_log("Login attempt while throttled for user '$username' from $clientIp", 'WARNING');

        // What may be said depends on which key tripped. A lock on the address
        // describes the caller, who already knows who they are. A lock on the
        // username would confirm that the account exists, so it gets the same
        // answer as a wrong password -- an attacker who has locked an account
        // out learns only that they are still wrong.
        $error = $throttle['by_address']
            ? "Too many failed login attempts from this address. "
                . "Please wait {$throttle['wait']} seconds and try again."
            : 'Invalid username or password';
    } else {
        $userData = verifyCredentials($username, $password);

        if ($userData === null) {
            authThrottleFail($username, $clientIp);

            auth_log("Authentication failed for user '$username' from $clientIp", 'WARNING');
            // Deliberately generic: do not reveal whether the account exists.
            $error = 'Invalid username or password';
        } else {
            authThrottleReset($username, $clientIp);

            establishSession($userData);
            auth_log("User $username successfully authenticated from $clientIp");

            header('Location: admin_dashboard.php');
            exit;
        }
    }
}

// The assets below are referenced relatively, so there is no base URL to
// determine. There used to be four variables here computing one, none of them
// read -- including $host, which took HTTP_HOST unvalidated. Harmless while
// nothing used it, which is exactly what would have made it survive until
// something did.
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
                                <?php echo h($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php endif; ?>
                            
                            <form method="post">
                                <?php echo csrfField(); ?>
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