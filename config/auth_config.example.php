<?php
/**
 * Authentication configuration for the ESXi Autodeploy admin.
 *
 * Copy to auth_config.php and replace every hash. Keep the live file outside
 * the web root (it belongs in /srv/autodeploy/config, never in www/).
 *
 * Generate a hash with:
 *   php lib/auth.php 'your-password'
 */

return [
    'users' => [
        'admin' => [
            // Replace this: it is a placeholder, not a usable hash.
            'password_hash' => 'CHANGEME_RUN_php_lib_auth.php_yourpassword',
            'role'          => 'admin',
            'name'          => 'Administrator',
        ],
    ],
    'roles' => [
        'admin' => [
            'description' => 'Full administrative access',
            'permissions' => ['read', 'write', 'approve', 'scan', 'settings'],
        ],
        'operator' => [
            'description' => 'Deployment operations access',
            'permissions' => ['read', 'approve', 'scan'],
        ],
    ],
];
