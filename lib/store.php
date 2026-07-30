<?php
/**
 * Persistence for hosts and credentials.
 *
 * Every read and write of the host inventory and the credential file goes
 * through this file. Nothing else should name AUTODEPLOY_HOSTS_CONFIG or
 * AUTODEPLOY_CREDENTIALS.
 *
 * That rule is what let the inventory move from a JSON file into SQLite, and
 * the credentials become encrypted at rest, without touching a single caller.
 * The hosts live in lib/db.php now; the credentials are still a file, because
 * they are a small nested document an operator fills in by hand at install
 * time and a database would take that away for no gain.
 *
 * lib/utils.php keeps the pure helpers -- formatMac(), findHostByMac() over a
 * config array, hostMatchesMac(). This file owns the I/O. The dependency runs
 * one way: store.php requires utils.php, never the reverse.
 */

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Hosts
// ---------------------------------------------------------------------------
//
// The inventory lives in SQLite (lib/db.php), but the array shape below is the
// one the rest of the application has always used, down to the nested "vlans"
// and "datastore" keys and the "additional_macs" list. Callers were not
// changed when the storage was: the mapping happens here.

if (!function_exists('storeHostColumns')) {
    /**
     * The host fields that have a column of their own.
     *
     * Everything else a caller attaches to a record is preserved in the
     * "extra" JSON column, so passing an unanticipated field through the store
     * does not silently lose it.
     *
     * @return string[]
     */
    function storeHostColumns() {
        return [
            'hostname', 'fqdn', 'esxi_version', 'deployment_type', 'deployment_status',
            'secure_boot_status', 'serial_number', 'ilo_ip', 'model', 'manufacturer',
            'bios_version', 'management_ip', 'management_netmask', 'management_gateway',
            'vmotion_ip', 'vmotion_netmask', 'datastore_name', 'progress', 'progress_text',
            'registered_time', 'last_seen', 'approved_time', 'deployment_started',
            'deployment_time', 'reinstall_requested',
        ];
    }
}

if (!function_exists('storeRowToHost')) {
    /**
     * Turn a database row into the array shape the application expects.
     *
     * @param array<string, mixed> $row            Row from the hosts table
     * @param string[]             $additionalMacs Secondary MACs for this host
     * @return array<string, mixed>
     */
    function storeRowToHost(array $row, array $additionalMacs = []) {
        $extra = json_decode((string)($row['extra'] ?? '{}'), true);
        if (!is_array($extra)) {
            $extra = [];
        }

        // Unmodelled fields first, so a column always wins over a stale copy
        // that happened to be carried in extra.
        $host = $extra;

        $host['mac_address'] = $row['mac'];

        foreach (storeHostColumns() as $column) {
            $host[$column] = $row[$column];
        }

        $host['progress'] = (int)$row['progress'];

        $host['vlans'] = [
            'management' => (int)$row['vlan_management'],
            'vmotion'    => (int)$row['vlan_vmotion'],
            'storage'    => (int)$row['vlan_storage'],
        ];

        // The drives list has no fixed shape, so it rides along in extra.
        $host['datastore'] = [
            'name'   => $row['datastore_name'],
            'drives' => $extra['datastore']['drives'] ?? [],
        ];

        if ($additionalMacs !== []) {
            $host['additional_macs'] = $additionalMacs;
        }

        return $host;
    }
}

if (!function_exists('storeHostToRow')) {
    /**
     * Turn an application host array into a row for the hosts table.
     *
     * @param array<string, mixed> $host Host record
     * @return array<string, mixed>|null Null when the record has no usable MAC
     */
    function storeHostToRow(array $host) {
        $mac = formatMac($host['mac_address'] ?? '');
        if ($mac === '') {
            return null;
        }

        $row = ['mac' => $mac];

        foreach (storeHostColumns() as $column) {
            $row[$column] = $host[$column] ?? null;
        }

        // NOT NULL columns take the empty string; the nullable timestamps stay
        // null so "never happened" is distinguishable from "happened at ''".
        foreach (storeHostColumns() as $column) {
            if ($row[$column] === null && !in_array($column, [
                'registered_time', 'last_seen', 'approved_time',
                'deployment_started', 'deployment_time', 'reinstall_requested',
            ], true)) {
                $row[$column] = '';
            }
        }

        $row['deployment_type']    = $row['deployment_type'] ?: 'standard';
        $row['deployment_status']  = $row['deployment_status'] ?: 'pending';
        $row['secure_boot_status'] = $row['secure_boot_status'] ?: 'unknown';
        $row['progress']           = (int)$row['progress'];

        $row['vlan_management'] = (int)($host['vlans']['management'] ?? 0);
        $row['vlan_vmotion']    = (int)($host['vlans']['vmotion'] ?? 0);
        $row['vlan_storage']    = (int)($host['vlans']['storage'] ?? 0);

        $row['datastore_name'] = $host['datastore']['name'] ?? ($host['datastore_name'] ?? '');
        if ($row['datastore_name'] === '') {
            $row['datastore_name'] = 'datastore1';
        }

        // Whatever is left over is kept verbatim.
        $extra = $host;
        unset($extra['mac_address'], $extra['vlans'], $extra['additional_macs'], $extra['datastore']);
        foreach (storeHostColumns() as $column) {
            unset($extra[$column]);
        }
        if (!empty($host['datastore']['drives'])) {
            $extra['datastore'] = ['drives' => $host['datastore']['drives']];
        }

        $row['extra'] = (string)json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $row;
    }
}

if (!function_exists('storeUpsertHostRow')) {
    /**
     * Insert or replace one host and its secondary MACs.
     *
     * Must be called inside a transaction: the host row and its MAC rows are
     * one record split across two tables.
     *
     * @param PDO                  $pdo  Connection
     * @param array<string, mixed> $host Host record
     * @return bool True when the record had a usable MAC and was written
     */
    function storeUpsertHostRow(PDO $pdo, array $host) {
        $row = storeHostToRow($host);
        if ($row === null) {
            return false;
        }

        $columns = array_keys($row);
        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $updates = array_map(
            static fn($c) => "$c = excluded.$c",
            array_filter($columns, static fn($c) => $c !== 'mac')
        );

        $sql = 'INSERT INTO hosts (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ') '
            . 'ON CONFLICT(mac) DO UPDATE SET ' . implode(', ', $updates);

        $pdo->prepare($sql)->execute($row);

        // Replace the secondary MACs wholesale: the caller hands over the
        // complete list, and merging would make removing one impossible.
        $pdo->prepare('DELETE FROM host_macs WHERE host_mac = ?')->execute([$row['mac']]);

        $insert = $pdo->prepare('INSERT OR IGNORE INTO host_macs (mac, host_mac) VALUES (?, ?)');
        foreach (($host['additional_macs'] ?? []) as $extraMac) {
            $normalised = formatMac($extraMac);
            // A secondary MAC equal to the primary would be a duplicate key,
            // and one already claimed by another host is ignored rather than
            // stolen -- INSERT OR IGNORE covers both.
            if ($normalised !== '' && $normalised !== $row['mac']) {
                $insert->execute([$normalised, $row['mac']]);
            }
        }

        return true;
    }
}

if (!function_exists('storeFetchHosts')) {
    /**
     * Read every host, with its secondary MACs attached.
     *
     * One query per table rather than a join with one row per MAC, so the
     * caller does not have to collapse duplicates.
     *
     * @param PDO $pdo Connection
     * @return array<int, array<string, mixed>>
     */
    function storeFetchHosts(PDO $pdo) {
        $macsByHost = [];
        foreach ($pdo->query('SELECT mac, host_mac FROM host_macs ORDER BY mac') as $row) {
            $macsByHost[$row['host_mac']][] = $row['mac'];
        }

        $hosts = [];
        foreach ($pdo->query('SELECT * FROM hosts ORDER BY hostname, mac') as $row) {
            $hosts[] = storeRowToHost($row, $macsByHost[$row['mac']] ?? []);
        }

        return $hosts;
    }
}

if (!function_exists('storeResolveMac')) {
    /**
     * Resolve any MAC a host owns to its primary MAC.
     *
     * @param PDO    $pdo Connection
     * @param string $mac MAC in any format
     * @return string The primary MAC, or '' when no host owns it
     */
    function storeResolveMac(PDO $pdo, $mac) {
        $mac = formatMac($mac);
        if ($mac === '') {
            return '';
        }

        $statement = $pdo->prepare('SELECT mac FROM hosts WHERE mac = ?');
        $statement->execute([$mac]);
        if ($statement->fetchColumn() !== false) {
            return $mac;
        }

        $statement = $pdo->prepare('SELECT host_mac FROM host_macs WHERE mac = ?');
        $statement->execute([$mac]);
        $primary = $statement->fetchColumn();

        return $primary === false ? '' : (string)$primary;
    }
}

if (!function_exists('storeLoadHostsConfig')) {
    /**
     * Load the whole inventory in the {"hosts": [...]} envelope.
     *
     * The envelope is kept because findHostByMac() takes it and the admin
     * dashboard iterates it. It is no longer a file, but the shape is the API
     * the rest of the tree was written against.
     *
     * @return array{hosts: array<int, array<string, mixed>>}|null
     */
    function storeLoadHostsConfig() {
        try {
            return ['hosts' => storeFetchHosts(db())];
        } catch (Throwable $e) {
            logMessage('Could not read the host inventory: ' . $e->getMessage(), 'ERROR');
            return null;
        }
    }
}

if (!function_exists('storeLoadHosts')) {
    /**
     * @return array<int, array<string, mixed>> Every host record
     */
    function storeLoadHosts() {
        $config = storeLoadHostsConfig();

        return $config === null ? [] : $config['hosts'];
    }
}

if (!function_exists('storeFindHost')) {
    /**
     * Look a host up by any of its MAC addresses.
     *
     * An indexed lookup rather than a scan of the whole inventory, which is
     * what the boot endpoints do on every request.
     *
     * @param string $mac MAC address in any format
     * @return array<string, mixed>|null
     */
    function storeFindHost($mac) {
        try {
            $pdo = db();

            $primary = storeResolveMac($pdo, $mac);
            if ($primary === '') {
                return null;
            }

            $statement = $pdo->prepare('SELECT * FROM hosts WHERE mac = ?');
            $statement->execute([$primary]);
            $row = $statement->fetch();
            if ($row === false) {
                return null;
            }

            $macs = $pdo->prepare('SELECT mac FROM host_macs WHERE host_mac = ? ORDER BY mac');
            $macs->execute([$primary]);

            return storeRowToHost($row, $macs->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            logMessage('Could not look up host: ' . $e->getMessage(), 'ERROR');
            return null;
        }
    }
}

if (!function_exists('storeMutateHosts')) {
    /**
     * Run a mutation over the whole host list inside one transaction.
     *
     * The callback receives the hosts as the array shape it always did and
     * returns false to abandon the write. It exists so the handlers written
     * against the JSON file did not have to be rewritten when the storage
     * changed; new code should prefer the narrower functions below.
     *
     * Reads and writes are both inside BEGIN IMMEDIATE, which is the part the
     * file-based version could not offer: there, a caller that read the
     * inventory, thought about it, and wrote it back could lose a concurrent
     * change.
     *
     * @param callable(array<int, array<string, mixed>>): bool $mutator
     * @return bool True when the mutation ran and was committed
     */
    function storeMutateHosts(callable $mutator) {
        try {
            return dbTransaction(static function (PDO $pdo) use ($mutator) {
                $before = storeFetchHosts($pdo);

                $hosts = $before;
                if ($mutator($hosts) === false) {
                    return false;
                }

                $keep = [];
                foreach ($hosts as $host) {
                    $mac = formatMac($host['mac_address'] ?? '');
                    if ($mac !== '') {
                        $keep[$mac] = true;
                    }
                }

                // Deletions first: a host removed and another renamed onto its
                // MAC in the same mutation must not collide.
                $delete = $pdo->prepare('DELETE FROM hosts WHERE mac = ?');
                foreach ($before as $host) {
                    $mac = formatMac($host['mac_address'] ?? '');
                    if ($mac !== '' && !isset($keep[$mac])) {
                        $delete->execute([$mac]);
                    }
                }

                foreach ($hosts as $host) {
                    if (is_array($host)) {
                        storeUpsertHostRow($pdo, $host);
                    }
                }

                return true;
            });
        } catch (Throwable $e) {
            logMessage('Could not update the host inventory: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

if (!function_exists('storeAddHost')) {
    /**
     * Insert a host, refusing to create a duplicate.
     *
     * The check and the insert are one transaction: two NICs of the same
     * server can reach the boot endpoint simultaneously, and checking first
     * and writing after produced two records or lost one of the writes.
     *
     * @param array<string, mixed> $host Host record, must carry mac_address
     * @return bool True when the host was inserted; false when it already existed
     */
    function storeAddHost(array $host) {
        $mac = formatMac($host['mac_address'] ?? '');
        if ($mac === '') {
            logMessage('Refusing to add a host without a valid MAC address', 'ERROR');
            return false;
        }

        $host['mac_address'] = $mac;

        try {
            return dbTransaction(static function (PDO $pdo) use ($mac, $host) {
                if (storeResolveMac($pdo, $mac) !== '') {
                    return false; // Already registered, possibly concurrently.
                }

                // A secondary MAC already claimed by another host means this
                // is that host arriving on a different port, not a new one.
                foreach (($host['additional_macs'] ?? []) as $extraMac) {
                    if (storeResolveMac($pdo, $extraMac) !== '') {
                        return false;
                    }
                }

                return storeUpsertHostRow($pdo, $host);
            });
        } catch (Throwable $e) {
            logMessage('Could not add host: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

if (!function_exists('storeUpdateHost')) {
    /**
     * Merge fields into a host record.
     *
     * @param string               $mac  MAC address of the host to update
     * @param array<string, mixed> $data Fields to merge
     * @return bool True when the host was found and the write succeeded
     */
    function storeUpdateHost($mac, array $data) {
        $mac = formatMac($mac);
        if ($mac === '') {
            return false;
        }

        try {
            $found = false;
            $ok = dbTransaction(static function (PDO $pdo) use ($mac, $data, &$found) {
                $primary = storeResolveMac($pdo, $mac);
                if ($primary === '') {
                    return false;
                }

                $statement = $pdo->prepare('SELECT * FROM hosts WHERE mac = ?');
                $statement->execute([$primary]);
                $row = $statement->fetch();
                if ($row === false) {
                    return false;
                }

                $macs = $pdo->prepare('SELECT mac FROM host_macs WHERE host_mac = ? ORDER BY mac');
                $macs->execute([$primary]);

                $host = storeRowToHost($row, $macs->fetchAll(PDO::FETCH_COLUMN));
                $merged = array_merge($host, $data);
                // The primary MAC identifies the row; a merge must not move it.
                $merged['mac_address'] = $primary;

                $found = true;

                return storeUpsertHostRow($pdo, $merged);
            });

            if (!$found) {
                logMessage("Host with MAC $mac not found for update", 'WARNING');
            }

            return $ok && $found;
        } catch (Throwable $e) {
            logMessage('Could not update host: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

if (!function_exists('storeDeleteHost')) {
    /**
     * Remove a host and any credential overrides recorded against it.
     *
     * The overrides go too so they cannot leak to a future host that happens
     * to reuse the MAC. host_macs follows the host by cascade.
     *
     * @param string $mac MAC address
     * @return bool True when a host was removed
     */
    function storeDeleteHost($mac) {
        $mac = formatMac($mac);
        if ($mac === '') {
            return false;
        }

        try {
            $primary = '';
            $ok = dbTransaction(static function (PDO $pdo) use ($mac, &$primary) {
                $primary = storeResolveMac($pdo, $mac);
                if ($primary === '') {
                    return false;
                }

                $pdo->prepare('DELETE FROM hosts WHERE mac = ?')->execute([$primary]);

                return true;
            });

            if ($ok && $primary !== '') {
                // Outside the transaction: the credentials are a separate file
                // and cannot join it. Ordered so a failure here leaves an
                // orphaned override rather than a host with no credentials.
                storeDeleteHostCredentials($primary);
            }

            return $ok;
        } catch (Throwable $e) {
            logMessage('Could not delete host: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

if (!function_exists('storeTouchHost')) {
    /**
     * Record that a host was seen, optionally with the serial it reported.
     *
     * @param string      $mac          MAC address
     * @param string|null $serialNumber Serial reported by the installer
     * @return bool True on success
     */
    function storeTouchHost($mac, $serialNumber = null) {
        $data = ['last_seen' => date('Y-m-d H:i:s')];

        if ($serialNumber !== null && $serialNumber !== '') {
            // Serial numbers arrive from the installer; keep them printable.
            $data['serial_number'] = preg_replace('/[^\x20-\x7E]/', '', (string)$serialNumber);
        }

        return storeUpdateHost($mac, $data);
    }
}

if (!function_exists('storeMergeDiscoveredHosts')) {
    /**
     * Merge the results of a hardware scan into the inventory.
     *
     * Only fields the scan actually discovered are refreshed on a host that
     * already exists. In particular mac_address is adopted only when the host
     * has none: overwriting it could silently point an already-approved host
     * at a different NIC.
     *
     * Discovered hosts carry no FQDN or management address -- an operator
     * supplies those at approval time -- so this deliberately does not go
     * through the host editor's validation. It is the reason discovery has an
     * endpoint of its own rather than reusing POST /v1/hosts.
     *
     * @param array<int, array<string, mixed>> $discovered Scan results
     * @return array{added: int, updated: int, ok: bool}
     */
    function storeMergeDiscoveredHosts(array $discovered) {
        $added = 0;
        $updated = 0;

        $ok = storeMutateHosts(static function (array &$hosts) use ($discovered, &$added, &$updated) {
            foreach ($discovered as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $serial = (string)($result['serial_number'] ?? '');
                $mac = formatMac($result['mac_address'] ?? '');

                $match = null;
                foreach ($hosts as $index => $host) {
                    // Serial first: it survives a NIC being replaced.
                    if ($serial !== '' && $serial !== 'Unknown'
                        && (string)($host['serial_number'] ?? '') === $serial) {
                        $match = $index;
                        break;
                    }
                    if ($mac !== '' && hostMatchesMac($host, $mac)) {
                        $match = $index;
                        break;
                    }
                }

                if ($match === null) {
                    $hosts[] = $result;
                    $added++;
                    continue;
                }

                foreach (['ilo_ip', 'model', 'manufacturer', 'bios_version',
                          'secure_boot_status', 'additional_macs'] as $field) {
                    if (!empty($result[$field])) {
                        $hosts[$match][$field] = $result[$field];
                    }
                }

                if (empty($hosts[$match]['serial_number']) && $serial !== '' && $serial !== 'Unknown') {
                    $hosts[$match]['serial_number'] = $serial;
                }

                if (empty($hosts[$match]['mac_address']) && $mac !== '') {
                    $hosts[$match]['mac_address'] = $mac;
                }

                $updated++;
            }

            return true;
        });

        return ['added' => $added, 'updated' => $updated, 'ok' => $ok];
    }
}

// ---------------------------------------------------------------------------
// Credentials
// ---------------------------------------------------------------------------

if (!function_exists('storeSecretFieldPaths')) {
    /**
     * Which leaves of the credentials document are secrets.
     *
     * Only these are encrypted. Usernames, and the structure itself, stay
     * readable so the file can still be inspected and hand-edited -- the point
     * is to protect the passwords, not to make the configuration opaque.
     *
     * @return array<string, string> Section => the key holding its password
     */
    function storeSecretFieldPaths() {
        return [
            'ilo'  => 'admin_password',
            'esxi' => 'root_password',
        ];
    }
}

if (!function_exists('storeMapCredentialSecrets')) {
    /**
     * Apply a transform to every secret leaf of a credentials document.
     *
     * The per-host override tables are walked too: an override holds the same
     * kind of password as the default it replaces, and leaving those in the
     * clear would protect the estate-wide account while exposing the ones set
     * on individual hosts.
     *
     * @param array<string, mixed>    $credentials Full document
     * @param callable(mixed): string $transform   Applied to each secret
     * @return array<string, mixed>
     */
    function storeMapCredentialSecrets(array $credentials, callable $transform) {
        foreach (storeSecretFieldPaths() as $section => $field) {
            if (!isset($credentials[$section]) || !is_array($credentials[$section])) {
                continue;
            }

            if (isset($credentials[$section][$field]) && is_string($credentials[$section][$field])) {
                $credentials[$section][$field] = $transform($credentials[$section][$field]);
            }

            if (!isset($credentials[$section]['hosts']) || !is_array($credentials[$section]['hosts'])) {
                continue;
            }

            foreach ($credentials[$section]['hosts'] as $mac => $override) {
                if (!is_array($override)) {
                    continue;
                }

                // iLO overrides use "password"; ESXi overrides use
                // "root_password". Both are secrets whatever they are called.
                foreach (['password', 'root_password', $field] as $key) {
                    if (isset($override[$key]) && is_string($override[$key])) {
                        $credentials[$section]['hosts'][$mac][$key] = $transform($override[$key]);
                    }
                }
            }
        }

        return $credentials;
    }
}

if (!function_exists('storeLoadCredentials')) {
    /**
     * Load credentials, optionally narrowed to a type and a specific host.
     *
     * Narrowing to a type drops the per-host override table from the result.
     * The previous implementation merged the override over the section and
     * left "hosts" in place, so asking for one host's ESXi password handed
     * back every other host's as well. No caller read it, but the API added
     * in this phase returns these structures over the wire.
     *
     * @param string|null $type Credential type (ilo, esxi)
     * @param string|null $mac  MAC for host-specific overrides
     * @return array<string, mixed>|null
     */
    function storeLoadCredentials($type = null, $mac = null) {
        $credentials = loadJsonConfig(AUTODEPLOY_CREDENTIALS);
        if ($credentials === null) {
            return null;
        }

        // Decrypted here, once, so every caller above this line deals in
        // plaintext and none of them needs to know the file is encrypted.
        $credentials = storeMapCredentialSecrets(
            $credentials,
            static fn($value) => secretDecryptOrPassThrough($value, 'a stored credential')
        );

        if ($type === null) {
            return $credentials;
        }

        if (!isset($credentials[$type]) || !is_array($credentials[$type])) {
            return null;
        }

        $section = $credentials[$type];

        if ($mac !== null) {
            $normalised = formatMac($mac);
            $override = $section['hosts'][$normalised] ?? null;
            if (is_array($override)) {
                // The host override wins field by field, so a per-host username
                // with no per-host password still falls back to the default.
                unset($section['hosts']);
                return array_merge($section, $override);
            }
        }

        unset($section['hosts']);

        return $section;
    }
}

if (!function_exists('storeSaveCredentials')) {
    /**
     * @param array<string, mixed> $credentials Complete credentials document
     * @return bool True on success
     */
    function storeSaveCredentials(array $credentials) {
        // Callers hand over plaintext, because that is what they were given.
        // Encrypting on the way out is what makes the round trip transparent
        // and what migrates a legacy plaintext file on its first write.
        $credentials = storeMapCredentialSecrets($credentials, 'secretEncrypt');

        $ok = saveJsonConfig(AUTODEPLOY_CREDENTIALS, $credentials);
        if ($ok) {
            @chmod(AUTODEPLOY_CREDENTIALS, 0640);
        }

        return $ok;
    }
}

if (!function_exists('storeDeleteHostCredentials')) {
    /**
     * Drop every per-host credential override for a MAC.
     *
     * @param string $mac Normalised MAC address
     * @return bool True on success
     */
    function storeDeleteHostCredentials($mac) {
        $mac = formatMac($mac);
        if ($mac === '') {
            return false;
        }

        $credentials = storeLoadCredentials();
        if (!is_array($credentials)) {
            return false;
        }

        unset($credentials['ilo']['hosts'][$mac], $credentials['esxi']['hosts'][$mac]);

        return storeSaveCredentials($credentials);
    }
}
