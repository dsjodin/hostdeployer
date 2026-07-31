<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/host_functions.php';

/**
 * The admin UI's four write paths: add, approve, reinstall, delete.
 *
 * They used to run through storeMutateHosts(), which read the whole inventory,
 * changed one field and wrote every host back. Rewriting them against the
 * narrow store functions changes how the write happens, so what is asserted
 * here is what must not change: the fields the form does not expose survive a
 * save, the ones it clears are cleared, and a host that is not there is
 * reported rather than created.
 */
final class HostActionsTest extends TestCase
{
    private const MAC = '00:0c:29:91:cf:eb';

    protected function setUp(): void
    {
        db()->exec('DELETE FROM hosts');

        file_put_contents(AUTODEPLOY_GLOBAL_CONFIG, json_encode([
            'deployment' => [
                'default_version' => '8.0U3',
                'esxi_versions'   => ['8.0U3' => ['path' => '/srv/autodeploy/esxi/8.0U3']],
            ],
        ]));

        file_put_contents(AUTODEPLOY_CREDENTIALS, json_encode([
            'ilo'  => ['admin_user' => 'Administrator', 'admin_password' => 'global', 'hosts' => []],
            'esxi' => ['root_password' => 'global', 'hosts' => []],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_GLOBAL_CONFIG);
        @unlink(AUTODEPLOY_CREDENTIALS);
        @unlink(AUTODEPLOY_CREDENTIALS . '.lock');
    }

    /**
     * A filled-in host editor form.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'mac'                => self::MAC,
            'hostname'           => 'esxi-01',
            'fqdn'               => 'esxi-01.example.com',
            'esxi_version'       => '8.0U3',
            'management_ip'      => '10.0.0.10',
            'management_netmask' => '255.255.255.0',
            'management_gateway' => '10.0.0.1',
            'vlan_mgmt'          => 100,
            'deployment_type'    => 'standard',
        ];
    }

    // -----------------------------------------------------------------------
    // Add
    // -----------------------------------------------------------------------

    public function testAddingCreatesTheHost(): void
    {
        $result = processAddHostAction($this->form());

        self::assertSame('', $result['error']);
        self::assertStringContainsString('added', $result['message']);

        $host = storeFindHost(self::MAC);
        self::assertNotNull($host);
        self::assertSame('esxi-01', $host['hostname']);
        self::assertSame('esxi-01.example.com', $host['fqdn']);
        self::assertSame('10.0.0.10', $host['management_ip']);
        self::assertSame('approved', $host['deployment_status']);
        self::assertSame(100, $host['vlans']['management']);
        self::assertSame('datastore1', $host['datastore']['name']);
        self::assertSame('unknown', $host['secure_boot_status']);
    }

    public function testSavingAgainUpdatesRatherThanDuplicates(): void
    {
        processAddHostAction($this->form());
        $result = processAddHostAction($this->form(['hostname' => 'esxi-01-renamed']));

        self::assertSame('', $result['error']);
        self::assertStringContainsString('updated', $result['message']);
        self::assertCount(1, storeLoadHosts());
        self::assertSame('esxi-01-renamed', storeFindHost(self::MAC)['hostname']);
    }

    /**
     * The reason the handler merges instead of replacing: serial numbers and
     * additional MACs come from the iLO scan, deployment history from the
     * installer, and none of them are on the form. A save that dropped them
     * would silently lose the scan.
     */
    public function testSavingKeepsFieldsTheFormDoesNotExpose(): void
    {
        storeAddHost([
            'mac_address'        => self::MAC,
            'hostname'           => 'discovered',
            'serial_number'      => 'CZ1234ABCD',
            'model'              => 'ProLiant DL380 Gen10',
            'manufacturer'       => 'HPE',
            'bios_version'       => 'U30 v2.44',
            'additional_macs'    => ['00:0c:29:91:cf:ec'],
            'deployment_time'    => '2026-01-01 00:00:00',
            'datastore'          => ['name' => 'datastore1', 'drives' => ['naa.600508b1']],
            'vlans'              => ['management' => 0, 'vmotion' => 0, 'storage' => 400],
        ]);

        processAddHostAction($this->form());

        $host = storeFindHost(self::MAC);
        self::assertSame('CZ1234ABCD', $host['serial_number']);
        self::assertSame('ProLiant DL380 Gen10', $host['model']);
        self::assertSame('HPE', $host['manufacturer']);
        self::assertSame('U30 v2.44', $host['bios_version']);
        self::assertSame(['00:0c:29:91:cf:ec'], $host['additional_macs']);
        self::assertSame('2026-01-01 00:00:00', $host['deployment_time']);
        self::assertSame(['naa.600508b1'], $host['datastore']['drives']);
        // Storage has no field on this form and has to survive the save.
        self::assertSame(400, $host['vlans']['storage']);
    }

    public function testSavingAgainstASecondaryMacDoesNotMoveTheRecord(): void
    {
        storeAddHost([
            'mac_address'     => self::MAC,
            'hostname'        => 'two-nics',
            'additional_macs' => ['00:0c:29:91:cf:ec'],
        ]);

        processAddHostAction($this->form(['mac' => '00:0c:29:91:cf:ec']));

        // The primary MAC identifies the row. Reaching the host through its
        // second NIC updates it; it does not promote that NIC to primary or
        // leave two records behind.
        self::assertCount(1, storeLoadHosts());
        self::assertSame(self::MAC, storeFindHost('00:0c:29:91:cf:ec')['mac_address']);
        self::assertSame('esxi-01', storeFindHost(self::MAC)['hostname']);
    }

    public function testVmotionIsClearedWhenTheFormLeavesItOut(): void
    {
        processAddHostAction($this->form([
            'vmotion_ip'      => '10.0.1.10',
            'vmotion_netmask' => '255.255.255.0',
            'vlan_vmotion'    => 200,
        ]));
        self::assertSame('10.0.1.10', storeFindHost(self::MAC)['vmotion_ip']);

        processAddHostAction($this->form());

        $host = storeFindHost(self::MAC);
        self::assertSame('', $host['vmotion_ip']);
        self::assertSame('', $host['vmotion_netmask']);
        self::assertSame(0, $host['vlans']['vmotion']);
    }

    public function testVcfLeavesNoVmotionBehind(): void
    {
        processAddHostAction($this->form([
            'deployment_type' => 'vcf',
            'vmotion_ip'      => '10.0.1.10',
            'vlan_vmotion'    => 200,
        ]));

        $host = storeFindHost(self::MAC);
        self::assertSame('vcf', $host['deployment_type']);
        // VCF configures vMotion itself during bring-up.
        self::assertSame('', $host['vmotion_ip']);
        self::assertSame(0, $host['vlans']['vmotion']);
    }

    public function testAnInvalidFormIsRejectedWithoutWriting(): void
    {
        self::assertNotSame('', processAddHostAction($this->form(['mac' => 'nonsense']))['error']);
        self::assertNotSame('', processAddHostAction($this->form(['fqdn' => 'not a hostname']))['error']);
        self::assertNotSame('', processAddHostAction($this->form(['management_ip' => '999.0.0.1']))['error']);

        self::assertSame([], storeLoadHosts());
    }

    // -----------------------------------------------------------------------
    // Approve
    // -----------------------------------------------------------------------

    public function testApprovingSetsTheStatusAndTheApprovalTime(): void
    {
        storeAddHost([
            'mac_address'       => self::MAC,
            'hostname'          => 'pending-host',
            'deployment_status' => 'pending',
        ]);

        $result = processApproveHostAction($this->form());
        self::assertSame('', $result['error']);

        $host = storeFindHost(self::MAC);
        self::assertSame('approved', $host['deployment_status']);
        self::assertSame('esxi-01', $host['hostname']);
        self::assertSame('10.0.0.10', $host['management_ip']);
        self::assertNotEmpty($host['approved_time']);
    }

    public function testApprovingKeepsTheStorageVlan(): void
    {
        storeAddHost([
            'mac_address'       => self::MAC,
            'deployment_status' => 'pending',
            'vlans'             => ['management' => 0, 'vmotion' => 0, 'storage' => 400],
        ]);

        processApproveHostAction($this->form(['vlan_mgmt' => 100]));

        $vlans = storeFindHost(self::MAC)['vlans'];
        self::assertSame(100, $vlans['management']);
        self::assertSame(400, $vlans['storage']);
    }

    public function testApprovingAnUnknownHostReportsRatherThanCreates(): void
    {
        $result = processApproveHostAction($this->form());

        self::assertStringContainsString('not found', $result['error']);
        self::assertSame([], storeLoadHosts());
    }

    // -----------------------------------------------------------------------
    // Reinstall
    // -----------------------------------------------------------------------

    public function testReinstallClearsTheDeploymentHistory(): void
    {
        storeAddHost([
            'mac_address'        => self::MAC,
            'hostname'           => 'esxi-01',
            'deployment_status'  => 'deployed',
            'deployment_started' => '2026-01-01 00:00:00',
            'deployment_time'    => '2026-01-01 00:30:00',
        ]);

        $result = processReinstallHostAction(['mac' => self::MAC]);
        self::assertSame('', $result['error']);

        $host = storeFindHost(self::MAC);
        self::assertSame('approved', $host['deployment_status']);
        self::assertNotEmpty($host['reinstall_requested']);
        // Cleared, not merely overwritten: these are what make the host look
        // deployed, and the boot chain refuses to reinstall a deployed host.
        self::assertNull($host['deployment_started']);
        self::assertNull($host['deployment_time']);
    }

    public function testReinstallingAnUnknownHostIsReported(): void
    {
        $result = processReinstallHostAction(['mac' => self::MAC]);

        self::assertStringContainsString('not found', $result['error']);
        self::assertSame([], storeLoadHosts());
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function testDeletingRemovesTheHostAndItsSecondaryMacs(): void
    {
        storeAddHost([
            'mac_address'     => self::MAC,
            'hostname'        => 'esxi-01',
            'additional_macs' => ['00:0c:29:91:cf:ec'],
        ]);

        $result = processDeleteHostAction(['mac' => self::MAC]);
        self::assertSame('', $result['error']);

        self::assertSame([], storeLoadHosts());
        self::assertNull(storeFindHost('00:0c:29:91:cf:ec'));
    }

    /**
     * The credential overrides have to go with the host, or they are handed to
     * whatever machine next turns up on that MAC.
     */
    public function testDeletingDropsTheCredentialOverrides(): void
    {
        storeAddHost(['mac_address' => self::MAC, 'hostname' => 'esxi-01']);

        processAddHostAction($this->form([
            'use_custom_ilo'  => '1',
            'ilo_username'    => 'local-admin',
            'ilo_password'    => 'local-secret',
            'use_custom_esxi' => '1',
            'esxi_password'   => 'local-root',
        ]));

        $credentials = storeLoadCredentials();
        self::assertArrayHasKey(self::MAC, $credentials['ilo']['hosts']);
        self::assertArrayHasKey(self::MAC, $credentials['esxi']['hosts']);

        processDeleteHostAction(['mac' => self::MAC]);

        $credentials = storeLoadCredentials();
        self::assertArrayNotHasKey(self::MAC, $credentials['ilo']['hosts']);
        self::assertArrayNotHasKey(self::MAC, $credentials['esxi']['hosts']);
    }

    public function testDeletingAnUnknownHostIsReported(): void
    {
        $result = processDeleteHostAction(['mac' => self::MAC]);

        self::assertStringContainsString('not found', $result['error']);
    }

    public function testDeletingReachesTheHostThroughASecondaryMac(): void
    {
        storeAddHost([
            'mac_address'     => self::MAC,
            'additional_macs' => ['00:0c:29:91:cf:ec'],
        ]);

        self::assertSame('', processDeleteHostAction(['mac' => '00:0c:29:91:cf:ec'])['error']);
        self::assertSame([], storeLoadHosts());
    }

    public function testDeletingRequiresAValidMac(): void
    {
        storeAddHost(['mac_address' => self::MAC]);

        self::assertNotSame('', processDeleteHostAction(['mac' => 'nonsense'])['error']);
        self::assertCount(1, storeLoadHosts());
    }
}
