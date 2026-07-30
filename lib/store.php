<?php
/**
 * Persistence for hosts and credentials.
 *
 * Every read and write of the host inventory and the credential file goes
 * through this file. Nothing else should name AUTODEPLOY_HOSTS_CONFIG or
 * AUTODEPLOY_CREDENTIALS.
 *
 * That rule is the point of the module. The storage is a JSON file today and
 * SQLite next; encrypting the credentials at rest happens in
 * storeLoadCredentials() and storeSaveCredentials() and nowhere else. Both of
 * those are swaps behind this interface only for as long as the interface is
 * the only way in.
 *
 * lib/utils.php keeps the pure helpers -- formatMac(), findHostByMac() over a
 * config array, hostMatchesMac(). This file owns the I/O. The dependency runs
 * one way: store.php requires utils.php, never the reverse.
 */

require_once __DIR__ . '/utils.php';

// ---------------------------------------------------------------------------
// Hosts
// ---------------------------------------------------------------------------

if (!function_exists('storeLoadHostsConfig')) {
    /**
     * Load the whole hosts document.
     *
     * Returns the {"hosts": [...]} envelope rather than a bare list because
     * that is the shape findHostByMac() takes and the shape the admin
     * dashboard iterates.
     *
     * @return array{hosts: array<int, array<string, mixed>>}|null Null when the file is unreadable
     */
    function storeLoadHostsConfig() {
        $config = loadJsonConfig(AUTODEPLOY_HOSTS_CONFIG);
        if ($config === null) {
            return null;
        }

        if (!isset($config['hosts']) || !is_array($config['hosts'])) {
            $config['hosts'] = [];
        }

        return $config;
    }
}

if (!function_exists('storeLoadHosts')) {
    /**
     * @return array<int, array<string, mixed>> Every host record, or an empty list when unreadable
     */
    function storeLoadHosts() {
        $config = storeLoadHostsConfig();

        return $config === null ? [] : array_values($config['hosts']);
    }
}

if (!function_exists('storeFindHost')) {
    /**
     * Look a host up by any of its MAC addresses.
     *
     * @param string $mac MAC address in any format
     * @return array<string, mixed>|null
     */
    function storeFindHost($mac) {
        $config = storeLoadHostsConfig();
        if ($config === null) {
            return null;
        }

        return findHostByMac($mac, $config);
    }
}

if (!function_exists('storeMutateHosts')) {
    /**
     * Run a mutation over the host list under an exclusive lock.
     *
     * The callback receives the hosts array by reference and returns false to
     * abandon the write. This is the seam the SQLite migration replaces with a
     * transaction, so callers must do all of their reading inside it: anything
     * read beforehand and written afterwards is a lost update.
     *
     * @param callable(array<int, array<string, mixed>>): bool $mutator
     * @return bool True when the mutation ran and the result was persisted
     */
    function storeMutateHosts(callable $mutator) {
        return updateJsonConfig(AUTODEPLOY_HOSTS_CONFIG, static function (array &$config) use ($mutator) {
            if (!isset($config['hosts']) || !is_array($config['hosts'])) {
                $config['hosts'] = [];
            }

            return $mutator($config['hosts']);
        });
    }
}

if (!function_exists('storeAddHost')) {
    /**
     * Insert a host, refusing to create a duplicate.
     *
     * The check happens inside the lock: two NICs of the same server can reach
     * the boot endpoint simultaneously, and checking first and writing after
     * produced two records or lost one of the writes.
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

        return storeMutateHosts(static function (array &$hosts) use ($mac, $host) {
            foreach ($hosts as $existing) {
                if (hostMatchesMac($existing, $mac)) {
                    return false; // Already registered, possibly by a concurrent request.
                }
            }

            $hosts[] = $host;

            return true;
        });
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

        $found = false;
        $ok = storeMutateHosts(static function (array &$hosts) use ($mac, $data, &$found) {
            foreach ($hosts as &$host) {
                if (hostMatchesMac($host, $mac)) {
                    $host = array_merge($host, $data);
                    $found = true;
                    break;
                }
            }
            unset($host);

            return $found;
        });

        if (!$found) {
            logMessage("Host with MAC $mac not found for update", 'WARNING');
        }

        return $ok && $found;
    }
}

if (!function_exists('storeDeleteHost')) {
    /**
     * Remove a host and any credential overrides recorded against it.
     *
     * The overrides go too so they cannot leak to a future host that happens
     * to reuse the MAC.
     *
     * @param string $mac MAC address
     * @return bool True when a host was removed
     */
    function storeDeleteHost($mac) {
        $mac = formatMac($mac);
        if ($mac === '') {
            return false;
        }

        $found = false;
        $ok = storeMutateHosts(static function (array &$hosts) use ($mac, &$found) {
            foreach ($hosts as $index => $host) {
                if (hostMatchesMac($host, $mac)) {
                    array_splice($hosts, $index, 1);
                    $found = true;
                    break;
                }
            }

            return $found;
        });

        if ($found && $ok) {
            storeDeleteHostCredentials($mac);
        }

        return $ok && $found;
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
     * The whole merge runs inside one lock. Reading the inventory, matching in
     * PHP and writing it back afterwards is how a concurrent scan and an
     * operator edit lose each other's work.
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
