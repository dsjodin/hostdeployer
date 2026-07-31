<?php
/**
 * SQLite connection and schema for the host inventory.
 *
 * The inventory used to be a JSON file rewritten in full on every change.
 * That works at fifty hosts and stops working somewhere above it: no indexes,
 * no transactions, and every write re-serialising the whole estate. The boot
 * endpoints touch it on every request, which is the worst possible shape for
 * a read-modify-write of one large document.
 *
 * Only the inventory moves. config/credentials.json stays an encrypted file:
 * it is a small nested document, it is read rarely, and the installation
 * instructions have the operator copy an example and fill in the CHANGEME
 * values by hand -- a workflow a database would take away for no gain.
 * global_config.json stays a file for the same reason.
 */

require_once __DIR__ . '/utils.php';

if (!defined('AUTODEPLOY_DB_FILE')) {
    define('AUTODEPLOY_DB_FILE', AUTODEPLOY_CONFIG_DIR . '/autodeploy.db');
}

if (!function_exists('db')) {
    /**
     * The shared PDO handle, opening and initialising the database on first use.
     *
     * @return PDO
     * @throws RuntimeException When the database cannot be opened
     */
    function db() {
        static $pdo = null;

        if ($pdo !== null) {
            return $pdo;
        }

        $dir = dirname(AUTODEPLOY_DB_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create $dir");
        }

        $fresh = !is_file(AUTODEPLOY_DB_FILE);

        $pdo = new PDO('sqlite:' . AUTODEPLOY_DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // WAL lets the boot endpoints read while an operator is writing.
        // Without it a dashboard save blocks a host mid-install.
        $pdo->exec('PRAGMA journal_mode = WAL');
        // Cascade host_macs when a host is deleted. SQLite has this off by
        // default and enforces it per connection, not per database.
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Two NICs of the same server can arrive together; wait rather than
        // returning "database is locked" to a booting host.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        dbCreateSchema($pdo);

        if ($fresh) {
            @chmod(AUTODEPLOY_DB_FILE, 0o640);
            logMessage('Created the host inventory database at ' . AUTODEPLOY_DB_FILE);
        }

        return $pdo;
    }
}

if (!function_exists('dbCreateSchema')) {
    /**
     * Create the schema if it is not already there.
     *
     * @param PDO $pdo Connection
     */
    function dbCreateSchema(PDO $pdo) {
        // The columns are the fields the application actually reads by name.
        // Anything else a caller attaches to a host record lands in "extra" as
        // JSON, so a round trip through the store never silently drops a field
        // this schema did not anticipate.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS hosts (
                mac                 TEXT PRIMARY KEY,
                hostname            TEXT    NOT NULL DEFAULT '',
                fqdn                TEXT    NOT NULL DEFAULT '',
                esxi_version        TEXT    NOT NULL DEFAULT '',
                deployment_type     TEXT    NOT NULL DEFAULT 'standard',
                deployment_status   TEXT    NOT NULL DEFAULT 'pending',
                secure_boot_status  TEXT    NOT NULL DEFAULT 'unknown',
                serial_number       TEXT    NOT NULL DEFAULT '',
                ilo_ip              TEXT    NOT NULL DEFAULT '',
                model               TEXT    NOT NULL DEFAULT '',
                manufacturer        TEXT    NOT NULL DEFAULT '',
                bios_version        TEXT    NOT NULL DEFAULT '',
                management_ip       TEXT    NOT NULL DEFAULT '',
                management_netmask  TEXT    NOT NULL DEFAULT '',
                management_gateway  TEXT    NOT NULL DEFAULT '',
                vmotion_ip          TEXT    NOT NULL DEFAULT '',
                vmotion_netmask     TEXT    NOT NULL DEFAULT '',
                vlan_management     INTEGER NOT NULL DEFAULT 0,
                vlan_vmotion        INTEGER NOT NULL DEFAULT 0,
                vlan_storage        INTEGER NOT NULL DEFAULT 0,
                datastore_name      TEXT    NOT NULL DEFAULT 'datastore1',
                progress            INTEGER NOT NULL DEFAULT 0,
                progress_text       TEXT    NOT NULL DEFAULT '',
                registered_time     TEXT,
                last_seen           TEXT,
                approved_time       TEXT,
                deployment_started  TEXT,
                deployment_time     TEXT,
                reinstall_requested TEXT,
                boot_token          TEXT,
                boot_token_expires  INTEGER NOT NULL DEFAULT 0,
                extra               TEXT    NOT NULL DEFAULT '{}'
            )
SQL);

        // A server reports whichever NIC booted, so every MAC a host owns has
        // to resolve to it. As a table this is an indexed lookup; as the JSON
        // array it replaced, it was a scan of every host on every boot request.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS host_macs (
                mac      TEXT PRIMARY KEY,
                host_mac TEXT NOT NULL REFERENCES hosts(mac) ON DELETE CASCADE
            )
SQL);

        // Failed logins, keyed by username and by source address separately.
        // In the session, which is where this counter used to live, it was
        // reset by any client that declined to send the cookie back -- so the
        // throttle only ever slowed down a browser.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS login_attempts (
                subject      TEXT PRIMARY KEY,
                failures     INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0,
                updated      INTEGER NOT NULL DEFAULT 0
            )
SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_host_macs_host ON host_macs(host_mac)');
        // The scanner matches on serial before MAC, so that lookup is indexed too.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hosts_serial ON hosts(serial_number)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hosts_status ON hosts(deployment_status)');

        dbAddMissingColumns($pdo);
    }
}

if (!function_exists('dbAddMissingColumns')) {
    /**
     * Add columns a later version introduced to a database that predates them.
     *
     * CREATE TABLE IF NOT EXISTS does nothing to a table that already exists,
     * so a new column reaches a fresh install and no other. Checked against
     * PRAGMA table_info rather than tracked with a version number: there are a
     * handful of columns, the check is exact, and a schema version is one more
     * thing that can disagree with the schema.
     *
     * @param PDO $pdo Connection
     */
    function dbAddMissingColumns(PDO $pdo) {
        $existing = [];
        foreach ($pdo->query('PRAGMA table_info(hosts)') as $column) {
            $existing[$column['name']] = true;
        }

        // Added when the boot chain stopped handing the ESXi root password
        // hash to anything that could name a MAC. See storeIssueBootToken().
        $added = [
            'boot_token'         => 'TEXT',
            'boot_token_expires' => 'INTEGER NOT NULL DEFAULT 0',
        ];

        foreach ($added as $name => $definition) {
            if (!isset($existing[$name])) {
                $pdo->exec("ALTER TABLE hosts ADD COLUMN $name $definition");
                logMessage("Added the hosts.$name column");
            }
        }
    }
}

if (!function_exists('dbTransaction')) {
    /**
     * Run a callback inside an immediate transaction.
     *
     * BEGIN IMMEDIATE rather than the default deferred: the callers all read
     * and then write, and a deferred transaction only takes the write lock at
     * the first write, which is exactly the window where two of them can read
     * the same state and both act on it.
     *
     * @param callable(PDO): bool $work Returns false to roll back
     * @return bool True when the work ran and was committed
     */
    function dbTransaction(callable $work) {
        $pdo = db();

        if ($pdo->inTransaction()) {
            // Nested calls join the outer transaction rather than committing
            // half of it early.
            return (bool)$work($pdo);
        }

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            if ($work($pdo) === false) {
                $pdo->exec('ROLLBACK');
                return false;
            }

            $pdo->exec('COMMIT');
            return true;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            logMessage('Transaction rolled back: ' . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
}
