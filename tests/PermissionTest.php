<?php
/**
 * The dashboard used to check the CSRF token and then dispatch, so every
 * account could do everything. These tests are about the table that replaced
 * that -- and about the failure that would bring it back: a form action added
 * without a line in it.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    protected function setUp(): void
    {
        file_put_contents(AUTODEPLOY_AUTH_CONFIG, '<?php return ' . var_export([
            'users' => [
                'admin'    => ['password_hash' => 'unused-here', 'role' => 'admin'],
                'operator' => ['password_hash' => 'unused-here', 'role' => 'operator'],
            ],
            'roles' => [
                // As install.sh writes them.
                'admin'    => ['permissions' => ['read', 'write', 'approve', 'scan', 'settings', 'templates']],
                'operator' => ['permissions' => ['read', 'approve', 'scan']],
                'viewer'   => ['permissions' => ['read']],
            ],
            'api_tokens' => [],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_AUTH_CONFIG);
    }

    // -- the table itself ---------------------------------------------------

    public function testAnUnknownActionIsRefused(): void
    {
        // Fail closed. A handler somebody adds without deciding who may reach
        // it should be unreachable, not open to everyone.
        self::assertFalse(actionPermission('drop_all_hosts'));
        self::assertFalse(actionPermission(''));
    }

    public function testLogoutNeedsNoPermission(): void
    {
        self::assertNull(actionPermission('logout'));
    }

    public function testEveryMappedPermissionIsOneTheApplicationKnows(): void
    {
        foreach (['add_host', 'approve_host', 'scan_ilo', 'save_global_config', 'save_template'] as $action) {
            self::assertContains(actionPermission($action), AUTODEPLOY_PERMISSIONS);
        }
    }

    /**
     * The regression this whole change exists to prevent: a form that posts an
     * action the table does not know about. Before, that action ran for
     * anybody; now it is refused -- but silently refusing a button the UI
     * still shows is its own bug, so the two lists have to agree.
     */
    public function testEveryFormActionInTheTreeIsInTheTable(): void
    {
        $missing = [];

        foreach (glob(__DIR__ . '/../www/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/name="action"\s+value="([a-z_]+)"/', $source, $matches);

            foreach ($matches[1] as $action) {
                // login.php is not routed through the dashboard.
                if ($action === 'login') {
                    continue;
                }
                if (actionPermission($action) === false) {
                    $missing[] = basename($file) . ': ' . $action;
                }
            }
        }

        self::assertSame([], array_unique($missing), 'form actions with no entry in actionPermission()');
    }

    public function testEveryTabHasAPermission(): void
    {
        foreach (['dashboard', 'hosts', 'scan', 'settings', 'templates'] as $tab) {
            self::assertContains(tabPermission($tab), AUTODEPLOY_PERMISSIONS);
        }
    }

    public function testAnUnknownTabDoesNotFallBackToSomethingPermissive(): void
    {
        self::assertNotSame('read', tabPermission('../../etc/passwd'));
    }

    // -- what each role may do ----------------------------------------------

    public function testAdminMayDoEverything(): void
    {
        foreach (AUTODEPLOY_PERMISSIONS as $permission) {
            self::assertTrue(roleHasPermission('admin', $permission), $permission);
        }
    }

    public function testOperatorHoldsOnlyItsThreePermissions(): void
    {
        foreach (['read', 'approve', 'scan'] as $permission) {
            self::assertTrue(roleHasPermission('operator', $permission), $permission);
        }

        foreach (['write', 'settings', 'templates'] as $permission) {
            self::assertFalse(roleHasPermission('operator', $permission), $permission);
        }
    }

    /**
     * The finding in one assertion. A kickstart template is a script that runs
     * as root in %firstboot on every host this appliance installs, so an
     * operator being able to edit one is closer to shell access on the estate
     * than to changing a setting.
     */
    public function testOperatorCannotEditKickstartTemplates(): void
    {
        foreach ([
            'save_template', 'create_template', 'upload_template', 'delete_template',
            'restore_backup', 'delete_backup', 'update_template_assignments',
        ] as $action) {
            self::assertFalse(
                roleHasPermission('operator', actionPermission($action)),
                "operator must not be able to '$action'"
            );
        }
    }

    public function testOperatorCannotReadOrWriteTheDefaultCredentials(): void
    {
        // save_default_credentials writes the ESXi root password every host is
        // installed with and the iLO account that can power cycle the estate.
        self::assertFalse(roleHasPermission('operator', actionPermission('save_default_credentials')));
        self::assertFalse(roleHasPermission('operator', tabPermission('settings')));
    }

    public function testOperatorCannotReconfigureDhcp(): void
    {
        self::assertFalse(roleHasPermission('operator', actionPermission('save_network_settings')));
    }

    public function testOperatorMayStillRunTheWorkflowItExistsFor(): void
    {
        // The point is to narrow the role, not to break it: approving a host
        // and running a scan are what an operator is for.
        foreach (['approve_host', 'reinstall_host', 'scan_ilo'] as $action) {
            self::assertTrue(
                roleHasPermission('operator', actionPermission($action)),
                "operator should still be able to '$action'"
            );
        }

        foreach (['dashboard', 'hosts', 'scan'] as $tab) {
            self::assertTrue(roleHasPermission('operator', tabPermission($tab)), $tab);
        }
    }

    public function testViewerMayOnlyLook(): void
    {
        foreach (['add_host', 'approve_host', 'scan_ilo', 'save_template', 'save_global_config'] as $action) {
            self::assertFalse(roleHasPermission('viewer', actionPermission($action)), $action);
        }

        self::assertTrue(roleHasPermission('viewer', tabPermission('hosts')));
    }

    public function testAnUnknownRoleHoldsNothing(): void
    {
        foreach (AUTODEPLOY_PERMISSIONS as $permission) {
            self::assertFalse(roleHasPermission('nonexistent', $permission), $permission);
            self::assertFalse(roleHasPermission('', $permission), $permission);
            self::assertFalse(roleHasPermission(null, $permission), $permission);
        }
    }
}
