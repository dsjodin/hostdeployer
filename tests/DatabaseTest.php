<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behaviour that only exists because the inventory is a database: the schema's
 * own guarantees, and the transaction boundary the JSON file could not offer.
 */
final class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM hosts');
    }

    public function testForeignKeysAreEnforcedOnThisConnection(): void
    {
        // SQLite has these off by default and enforces them per connection,
        // so the PRAGMA is load-bearing rather than decorative.
        self::assertSame(1, (int)db()->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testWalIsEnabled(): void
    {
        // Without WAL, an operator saving a host blocks a booting one.
        self::assertSame('wal', strtolower((string)db()->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testSecondaryMacsAreDeletedWithTheirHost(): void
    {
        storeAddHost([
            'mac_address'     => '00:0c:29:91:cf:eb',
            'additional_macs' => ['00:0c:29:91:cf:ec', '00:0c:29:91:cf:ed'],
        ]);

        self::assertSame(2, (int)db()->query('SELECT COUNT(*) FROM host_macs')->fetchColumn());

        storeDeleteHost('00:0c:29:91:cf:eb');

        self::assertSame(
            0,
            (int)db()->query('SELECT COUNT(*) FROM host_macs')->fetchColumn(),
            'the cascade should leave no orphaned MACs'
        );
    }

    public function testASecondaryMacCannotBeClaimedByTwoHosts(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'additional_macs' => ['aa:bb:cc:dd:ee:ff']]);

        // The second host wants a MAC the first already owns. It is the same
        // server arriving on another port, not a new one.
        self::assertFalse(storeAddHost([
            'mac_address'     => '11:22:33:44:55:66',
            'additional_macs' => ['aa:bb:cc:dd:ee:ff'],
        ]));

        self::assertCount(1, storeLoadHosts());
    }

    public function testASecondaryMacEqualToThePrimaryIsIgnored(): void
    {
        // Would otherwise be a duplicate primary key in host_macs.
        self::assertTrue(storeAddHost([
            'mac_address'     => '00:0c:29:91:cf:eb',
            'additional_macs' => ['00:0c:29:91:cf:eb'],
        ]));

        self::assertSame(0, (int)db()->query('SELECT COUNT(*) FROM host_macs')->fetchColumn());
    }

    public function testRemovingASecondaryMacActuallyRemovesIt(): void
    {
        storeAddHost([
            'mac_address'     => '00:0c:29:91:cf:eb',
            'additional_macs' => ['00:0c:29:91:cf:ec', '00:0c:29:91:cf:ed'],
        ]);

        storeUpdateHost('00:0c:29:91:cf:eb', ['additional_macs' => ['00:0c:29:91:cf:ec']]);

        self::assertSame(['00:0c:29:91:cf:ec'], storeFindHost('00:0c:29:91:cf:eb')['additional_macs']);
        self::assertNull(storeFindHost('00:0c:29:91:cf:ed'), 'the dropped MAC no longer resolves');
    }

    /**
     * The schema cannot anticipate every field a caller attaches to a host.
     * Losing one silently on a round trip would be the worst outcome of this
     * migration, so unmodelled fields go to the "extra" column.
     */
    public function testUnmodelledFieldsSurviveARoundTrip(): void
    {
        storeAddHost([
            'mac_address' => '00:0c:29:91:cf:eb',
            'hostname'    => 'esxi-01',
            'notes'       => 'rack 4, top of unit',
            'custom'      => ['nested' => ['deeply', 'structured']],
        ]);

        $host = storeFindHost('00:0c:29:91:cf:eb');

        self::assertSame('rack 4, top of unit', $host['notes']);
        self::assertSame(['nested' => ['deeply', 'structured']], $host['custom']);
        self::assertSame('esxi-01', $host['hostname'], 'modelled fields still come from their column');
    }

    public function testTheDatastoreDriveListSurvives(): void
    {
        storeAddHost([
            'mac_address' => '00:0c:29:91:cf:eb',
            'datastore'   => ['name' => 'ds-local', 'drives' => ['naa.600', 'naa.601']],
        ]);

        $host = storeFindHost('00:0c:29:91:cf:eb');

        self::assertSame('ds-local', $host['datastore']['name']);
        self::assertSame(['naa.600', 'naa.601'], $host['datastore']['drives']);
    }

    public function testVlansRoundTripAsIntegers(): void
    {
        storeAddHost([
            'mac_address' => '00:0c:29:91:cf:eb',
            'vlans'       => ['management' => '100', 'vmotion' => 200, 'storage' => 0],
        ]);

        self::assertSame(
            ['management' => 100, 'vmotion' => 200, 'storage' => 0],
            storeFindHost('00:0c:29:91:cf:eb')['vlans']
        );
    }

    public function testDefaultsAreAppliedToAMinimalRecord(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb']);

        $host = storeFindHost('00:0c:29:91:cf:eb');

        self::assertSame('pending', $host['deployment_status']);
        self::assertSame('standard', $host['deployment_type']);
        self::assertSame('unknown', $host['secure_boot_status']);
        self::assertSame('datastore1', $host['datastore']['name']);
        self::assertSame(0, $host['progress']);
    }

    public function testTimestampsThatNeverHappenedStayNull(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb']);

        $host = storeFindHost('00:0c:29:91:cf:eb');

        // Distinguishable from "happened at ''", which is what an empty string
        // default would have made them.
        self::assertNull($host['deployment_time']);
        self::assertNull($host['approved_time']);
    }

    /**
     * The transaction boundary is the thing the JSON file could not offer: a
     * mutation that fails part way must leave nothing behind.
     */
    public function testAFailedMutationRollsBackEverything(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'original']);

        $result = storeMutateHosts(static function (array &$hosts): bool {
            $hosts[0]['hostname'] = 'changed';
            $hosts[] = ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'added'];

            return false; // Abandon.
        });

        self::assertFalse($result);
        self::assertCount(1, storeLoadHosts());
        self::assertSame('original', storeFindHost('00:0c:29:91:cf:eb')['hostname']);
    }

    public function testAThrowingMutationRollsBackAndReportsFailure(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'original']);

        $result = storeMutateHosts(static function (array &$hosts): bool {
            $hosts[0]['hostname'] = 'changed';
            throw new RuntimeException('something went wrong mid-mutation');
        });

        self::assertFalse($result);
        self::assertSame('original', storeFindHost('00:0c:29:91:cf:eb')['hostname']);
        self::assertFalse(db()->inTransaction(), 'the connection must not be left in a transaction');
    }

    public function testAMutationCanDeleteAndReAddInOneGo(): void
    {
        storeAddHost(['mac_address' => '00:0c:29:91:cf:eb', 'hostname' => 'first']);
        storeAddHost(['mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'second']);

        storeMutateHosts(static function (array &$hosts): bool {
            $hosts = [['mac_address' => '11:22:33:44:55:66', 'hostname' => 'replacement']];
            return true;
        });

        $hosts = storeLoadHosts();
        self::assertCount(1, $hosts);
        self::assertSame('replacement', $hosts[0]['hostname']);
    }

    public function testTheLookupIndexesExist(): void
    {
        $indexes = db()
            ->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_%'")
            ->fetchAll(PDO::FETCH_COLUMN);

        // The boot path resolves a MAC and the scanner matches on serial, both
        // on every request. Neither should be a table scan.
        self::assertContains('idx_host_macs_host', $indexes);
        self::assertContains('idx_hosts_serial', $indexes);
        self::assertContains('idx_hosts_status', $indexes);
    }
}
