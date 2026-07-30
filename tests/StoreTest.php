<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The store is the only thing that touches hosts.json, so its concurrency
 * behaviour is the inventory's concurrency behaviour.
 */
final class StoreTest extends TestCase
{
    protected function setUp(): void
    {
        // A clean inventory per test: the fixture root is shared for the run.
        file_put_contents(AUTODEPLOY_HOSTS_CONFIG, json_encode(['hosts' => []]));
        file_put_contents(AUTODEPLOY_CREDENTIALS, json_encode([
            'ilo'  => ['admin_user' => 'Administrator', 'admin_password' => 'global-ilo', 'hosts' => []],
            'esxi' => ['root_password' => 'global-esxi', 'hosts' => []],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink(AUTODEPLOY_HOSTS_CONFIG);
        @unlink(AUTODEPLOY_CREDENTIALS);
        @unlink(AUTODEPLOY_HOSTS_CONFIG . '.lock');
        @unlink(AUTODEPLOY_CREDENTIALS . '.lock');
    }

    /** @return array<string, mixed> The credentials file exactly as stored */
    private function rawCredentialsFile(): array
    {
        return (array)json_decode((string)file_get_contents(AUTODEPLOY_CREDENTIALS), true);
    }

    public function testAddsAndFindsAHost(): void
    {
        self::assertTrue(storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'esxi-01']));

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertNotNull($host);
        self::assertSame('esxi-01', $host['hostname']);
    }

    public function testNormalisesTheMacOnInsert(): void
    {
        self::assertTrue(storeAddHost(['mac_address' => '00-0C-29-91-CF-EB']));

        $host = storeFindHost('000c2991cfeb');
        self::assertNotNull($host);
        self::assertSame('00:0c:29:91:cf:eb', $host['mac_address']);
    }

    /**
     * Two NICs of the same server reach the boot endpoint at once. The second
     * insert must be refused rather than producing a duplicate record.
     */
    public function testRefusesADuplicateHost(): void
    {
        self::assertTrue(storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'first']));
        self::assertFalse(storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'second']));

        self::assertCount(1, storeLoadHosts());
        self::assertSame('first', storeFindHost('00:0c:29:91:cf:eb')['hostname']);
    }

    public function testRefusesAHostWithoutAValidMac(): void
    {
        self::assertFalse(storeAddHost(['hostname' => 'no-mac']));
        self::assertFalse(storeAddHost(['mac_address' => 'nonsense']));
        self::assertSame([], storeLoadHosts());
    }

    public function testUpdateMergesRatherThanReplaces(): void
    {
        storeAddHost([
            'mac_address' => '00:0c:29:91:cf:eb',
            'hostname'    => 'esxi-01',
            'serial_number' => 'ABC123',
        ]);

        self::assertTrue(storeUpdateHost('00:0c:29:91:cf:eb', ['deployment_status' => 'approved']));

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame('approved', $host['deployment_status']);
        self::assertSame('ABC123', $host['serial_number'], 'untouched fields must survive');
    }

    public function testUpdateReportsAMissingHost(): void
    {
        self::assertFalse(storeUpdateHost('00:0c:29:91:cf:eb', ['deployment_status' => 'approved']));
    }

    public function testFindsAndUpdatesByAnAdditionalMac(): void
    {
        storeAddHost([
            'mac_address'     => '00:0c:29:91:cf:eb',
            'hostname'        => 'esxi-01',
            'additional_macs' => ['00:0c:29:91:cf:ec'],
        ]);

        self::assertNotNull(storeFindHost('00:0c:29:91:cf:ec'));
        self::assertTrue(storeUpdateHost('00:0c:29:91:cf:ec', ['deployment_status' => 'deployed']));
        self::assertSame('deployed', storeFindHost('00:0c:29:91:cf:eb')['deployment_status']);
    }

    public function testDeleteRemovesTheHostAndItsCredentialOverrides(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb']);

        $credentials = storeLoadCredentials();
        $credentials['esxi']['hosts']['00:0c:29:91:cf:eb'] = ['root_password' => 'per-host'];
        storeSaveCredentials($credentials);

        self::assertTrue(storeDeleteHost('00:0c:29:91:cf:eb'));
        self::assertSame([], storeLoadHosts());

        $after = storeLoadCredentials();
        self::assertArrayNotHasKey(
            '00:0c:29:91:cf:eb',
            $after['esxi']['hosts'],
            'a future host reusing the MAC must not inherit the password'
        );
    }

    public function testDeleteReportsAMissingHost(): void
    {
        self::assertFalse(storeDeleteHost('00:0c:29:91:cf:eb'));
    }

    public function testTouchRecordsTheSerialAndStripsControlCharacters(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb']);

        self::assertTrue(storeTouchHost('00:0c:29:91:cf:eb', "ABC\x00123\n"));

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame('ABC123', $host['serial_number']);
        self::assertNotEmpty($host['last_seen']);
    }

    // -- credentials --------------------------------------------------------

    public function testLoadsTheDefaultsForAType(): void
    {
        $ilo = storeLoadCredentials('ilo');

        self::assertSame('Administrator', $ilo['admin_user']);
        self::assertSame('global-ilo', $ilo['admin_password']);
    }

    public function testAPerHostOverrideWins(): void
    {
        $credentials = storeLoadCredentials();
        $credentials['ilo']['hosts']['00:0c:29:91:cf:eb'] = ['password' => 'per-host'];
        storeSaveCredentials($credentials);

        $resolved = storeLoadCredentials('ilo', '00:0c:29:91:cf:eb');

        self::assertSame('per-host', $resolved['password']);
        self::assertSame('Administrator', $resolved['admin_user'], 'unset fields fall back to the default');
    }

    /**
     * Narrowing to a type must not carry every other host's secrets along
     * with the answer; the API returns these structures over the wire.
     */
    public function testNarrowingDropsTheOverrideTable(): void
    {
        $credentials = storeLoadCredentials();
        $credentials['esxi']['hosts']['aa:bb:cc:dd:ee:ff'] = ['root_password' => 'someone-elses'];
        storeSaveCredentials($credentials);

        foreach ([storeLoadCredentials('esxi'), storeLoadCredentials('esxi', '00:0c:29:91:cf:eb')] as $resolved) {
            self::assertArrayNotHasKey('hosts', $resolved);
            self::assertStringNotContainsString('someone-elses', (string)json_encode($resolved));
        }
    }

    public function testLoadingEverythingStillIncludesTheOverrideTable(): void
    {
        // The settings screen and the host editor rewrite the whole document.
        self::assertArrayHasKey('hosts', storeLoadCredentials()['esxi']);
    }

    public function testUnknownCredentialTypeIsNull(): void
    {
        self::assertNull(storeLoadCredentials('nonexistent'));
    }

    // -- encryption at rest -------------------------------------------------

    /**
     * The whole point of phase 3: what reaches the disk must not be readable.
     */
    public function testSecretsAreEncryptedOnDisk(): void
    {
        $credentials = storeLoadCredentials();
        $credentials['ilo']['hosts']['00:0c:29:91:cf:eb'] = ['password' => 'per-host-ilo'];
        $credentials['esxi']['hosts']['00:0c:29:91:cf:eb'] = ['root_password' => 'per-host-esxi'];
        storeSaveCredentials($credentials);

        $onDisk = (string)file_get_contents(AUTODEPLOY_CREDENTIALS);

        foreach (['global-ilo', 'global-esxi', 'per-host-ilo', 'per-host-esxi'] as $secret) {
            self::assertStringNotContainsString($secret, $onDisk, "$secret reached the disk in the clear");
        }

        $raw = $this->rawCredentialsFile();
        self::assertTrue(secretIsEncrypted($raw['ilo']['admin_password']));
        self::assertTrue(secretIsEncrypted($raw['esxi']['root_password']));
        self::assertTrue(secretIsEncrypted($raw['ilo']['hosts']['00:0c:29:91:cf:eb']['password']));
        self::assertTrue(secretIsEncrypted($raw['esxi']['hosts']['00:0c:29:91:cf:eb']['root_password']));
    }

    public function testUsernamesAndStructureStayReadable(): void
    {
        storeSaveCredentials(storeLoadCredentials());

        $raw = $this->rawCredentialsFile();

        self::assertSame('Administrator', $raw['ilo']['admin_user'], 'only passwords are encrypted');
        self::assertArrayHasKey('hosts', $raw['ilo']);
    }

    public function testCallersStillSeePlaintext(): void
    {
        storeSaveCredentials(storeLoadCredentials());

        self::assertSame('global-ilo', storeLoadCredentials('ilo')['admin_password']);
        self::assertSame('global-esxi', storeLoadCredentials('esxi')['root_password']);
    }

    public function testAnEncryptedPerHostOverrideStillWins(): void
    {
        $credentials = storeLoadCredentials();
        $credentials['esxi']['hosts']['00:0c:29:91:cf:eb'] = ['root_password' => 'per-host'];
        storeSaveCredentials($credentials);

        self::assertSame(
            'per-host',
            storeLoadCredentials('esxi', '00:0c:29:91:cf:eb')['root_password']
        );
        self::assertSame('global-esxi', storeLoadCredentials('esxi')['root_password']);
    }

    /**
     * setUp() writes a plaintext file, which is what an install predating this
     * change looks like. It has to keep working, and encrypt itself on write.
     */
    public function testALegacyPlaintextFileIsReadableAndMigratesOnSave(): void
    {
        self::assertSame('global-esxi', storeLoadCredentials('esxi')['root_password']);

        self::assertFalse(
            secretIsEncrypted($this->rawCredentialsFile()['esxi']['root_password']),
            'the fixture starts out in the clear'
        );

        storeSaveCredentials(storeLoadCredentials());

        self::assertTrue(secretIsEncrypted($this->rawCredentialsFile()['esxi']['root_password']));
        self::assertSame('global-esxi', storeLoadCredentials('esxi')['root_password']);
    }

    public function testRepeatedSavesDoNotDoubleEncrypt(): void
    {
        for ($i = 0; $i < 3; $i++) {
            storeSaveCredentials(storeLoadCredentials());
        }

        self::assertSame('global-esxi', storeLoadCredentials('esxi')['root_password']);
    }

    public function testAPartlyMigratedFileIsHandled(): void
    {
        // One secret already encrypted, one still in the clear -- what an
        // interrupted migration leaves behind.
        $raw = $this->rawCredentialsFile();
        $raw['ilo']['admin_password'] = secretEncrypt('global-ilo');
        file_put_contents(AUTODEPLOY_CREDENTIALS, json_encode($raw));

        $loaded = storeLoadCredentials();
        self::assertSame('global-ilo', $loaded['ilo']['admin_password']);
        self::assertSame('global-esxi', $loaded['esxi']['root_password']);
    }

    // -- discovery merge ----------------------------------------------------

    public function testDiscoveryAddsUnknownHosts(): void
    {
        $result = storeMergeDiscoveredHosts([
            ['mac_address' => '00:0c:29:91:cf:eb', 'serial_number' => 'ABC123', 'ilo_ip' => '10.0.1.5'],
        ]);

        self::assertSame(['added' => 1, 'updated' => 0, 'ok' => true], $result);
        self::assertSame('10.0.1.5', storeFindHost('00:0c:29:91:cf:eb')['ilo_ip']);
    }

    public function testDiscoveryMatchesOnSerialWhenTheMacChanged(): void
    {
        storeAddHost([
            'mac_address'   => '00:0c:29:91:cf:eb',
            'serial_number' => 'ABC123',
            'hostname'      => 'esxi-01',
        ]);

        $result = storeMergeDiscoveredHosts([
            ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'serial_number' => 'ABC123', 'ilo_ip' => '10.0.1.9'],
        ]);

        self::assertSame(1, $result['updated']);
        self::assertCount(1, storeLoadHosts());

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame('10.0.1.9', $host['ilo_ip']);
        self::assertSame(
            '00:0c:29:91:cf:eb',
            $host['mac_address'],
            'an approved host must not be repointed at a different NIC'
        );
    }

    public function testDiscoveryAdoptsAMacOnlyWhenTheHostHasNone(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'serial_number' => 'ABC123']);
        // A record with no MAC cannot be inserted through storeAddHost, so
        // write one the way an operator creating a placeholder would.
        storeMutateHosts(static function (array &$hosts): bool {
            $hosts[] = ['serial_number' => 'DEF456', 'hostname' => 'placeholder'];
            return true;
        });

        storeMergeDiscoveredHosts([
            ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'serial_number' => 'DEF456'],
        ]);

        $hosts = storeLoadHosts();
        $placeholder = array_values(array_filter(
            $hosts,
            static fn(array $h): bool => ($h['serial_number'] ?? '') === 'DEF456'
        ))[0];

        self::assertSame('aa:bb:cc:dd:ee:ff', $placeholder['mac_address']);
    }

    public function testDiscoveryIgnoresUnknownSerials(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'serial_number' => 'Unknown']);

        // "Unknown" is what the scanner reports when it could not read one; it
        // must not match every other host that also failed to report a serial.
        $result = storeMergeDiscoveredHosts([
            ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'serial_number' => 'Unknown'],
        ]);

        self::assertSame(1, $result['added']);
        self::assertCount(2, storeLoadHosts());
    }

    public function testDiscoveryDoesNotBlankFieldsWithEmptyValues(): void
    {
        storeAddHost([
            'mac_address' => '00:0c:29:91:cf:eb',
            'ilo_ip'      => '10.0.1.5',
            'model'       => 'ProLiant DL380 Gen10',
        ]);

        storeMergeDiscoveredHosts([
            ['mac_address' => '00:0c:29:91:cf:eb', 'ilo_ip' => '', 'model' => null],
        ]);

        $host = storeFindHost('00:0c:29:91:cf:eb');
        self::assertSame('10.0.1.5', $host['ilo_ip']);
        self::assertSame('ProLiant DL380 Gen10', $host['model']);
    }
}
