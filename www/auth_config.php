<?php
/**
 * Authentication configuration for ESXi Autodeploy Admin
 * 
 * This file defines the users and their roles for the admin interface.
 * Store this file outside of the web root for security.
 */

// Generate password hashes using: php -r "require('/path/to/auth.php'); echo generatePasswordHash('your_password');"

return [
    'users' => [
        'admin' => [
            'password_hash' => '$2y$10$anWtKR1OtlSSMHGuhHKZmu14NXReHYZu55F1wjpJcancUcncgH0xG', // Password: password
            'role' => 'admin',
            'name' => 'Administrator'
        ],
        'operator' => [
            'password_hash' => '$2y$10$lJQY7VFtV6UVl2nBkWeQWOGvfVyWKr7DBEhQNqVVg.sHsP1jofILW', // Password: operator
            'role' => 'operator',
            'name' => 'Deployment Operator'
        ]
    ],
    'roles' => [
        'admin' => [
            'description' => 'Full administrative access',
            'permissions' => ['read', 'write', 'approve', 'scan', 'settings']
        ],
        'operator' => [
            'description' => 'Deployment operations access',
            'permissions' => ['read', 'approve', 'scan']
        ]
    ]
];