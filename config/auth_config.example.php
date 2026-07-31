<?php
/**
 * Authentication configuration for the ESXi Autodeploy admin.
 *
 * Copy to auth_config.php and replace every hash. Keep the live file outside
 * the web root (it belongs in /srv/autodeploy/config, never in www/).
 *
 * Generate a hash with:
 *   php lib/auth.php 'your-password'
 *
 * Generate an API token with:
 *   php lib/api_auth.php automation
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
            'permissions' => ['read', 'write', 'approve', 'scan', 'settings', 'templates'],
        ],
        'operator' => [
            'description' => 'Deployment operations access',
            'permissions' => ['read', 'approve', 'scan'],
        ],
    ],

    // Bearer tokens for the REST API. Each is stored as a SHA-256 digest, so
    // the token itself appears nowhere on disk and cannot be recovered from
    // this file -- generate a replacement if one is lost.
    //
    // The role decides what the token may do, using the same permission table
    // as the interactive accounts above. Give automation the narrowest role
    // that works: reading /v1/credentials requires 'settings', which by
    // default only admin holds, because it hands out ESXi root passwords.
    //
    // The Python helpers in scripts/ read their token from AUTODEPLOY_API_TOKEN
    // and need 'read', 'write' and 'settings' to populate the inventory from an
    // iLO scan, so they are given the admin role here.
    'api_tokens' => [
        // 'ilo-scanner' => [
        //     'token_hash' => 'CHANGEME_RUN_php_lib_api_auth.php_ilo-scanner',
        //     'role'       => 'admin',
        // ],
    ],
];
