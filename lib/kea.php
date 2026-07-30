<?php
/**
 * Client for the Kea DHCPv4 control socket.
 *
 * Changing the DHCP settings used to mean regenerating /etc/kea/kea-dhcp4.conf
 * from a shell script, running it through a syntax check and restarting the
 * daemon -- which needed the web server to be able to run something as root
 * through a sudo rule, and dropped every DHCP exchange in flight at the moment
 * of the restart.
 *
 * Kea has an API for exactly this. config-test validates, config-set applies
 * to the running server without restarting it, and config-write persists.
 * None of that needs root: it needs write access to a unix socket.
 *
 * The socket is the trust boundary. Anything that can write to it can
 * reconfigure DHCP for the whole provisioning network, which is why
 * install.sh grants it to the web server's group and nothing wider.
 */

require_once __DIR__ . '/utils.php';

if (!defined('AUTODEPLOY_KEA_SOCKET')) {
    define('AUTODEPLOY_KEA_SOCKET', getenv('AUTODEPLOY_KEA_SOCKET') ?: '/run/kea/kea4-ctrl-socket');
}

/** How long to wait for Kea to answer, in seconds. */
if (!defined('AUTODEPLOY_KEA_TIMEOUT')) {
    define('AUTODEPLOY_KEA_TIMEOUT', 10);
}

class KeaException extends RuntimeException
{
}

if (!function_exists('keaAvailable')) {
    /**
     * Whether the control socket is there and writable by this process.
     *
     * @return bool
     */
    function keaAvailable() {
        return file_exists(AUTODEPLOY_KEA_SOCKET) && is_writable(AUTODEPLOY_KEA_SOCKET);
    }
}

if (!function_exists('keaCommand')) {
    /**
     * Send one command to Kea and return its arguments.
     *
     * @param string               $command   Kea command name
     * @param array<string, mixed> $arguments Command arguments
     * @return array<string, mixed> The response arguments, or an empty array
     * @throws KeaException When the socket is unreachable or Kea reports failure
     */
    function keaCommand($command, array $arguments = []) {
        if (!file_exists(AUTODEPLOY_KEA_SOCKET)) {
            throw new KeaException(
                'The Kea control socket is not there (' . AUTODEPLOY_KEA_SOCKET . '). '
                . 'Is kea-dhcp4-server running?'
            );
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://' . AUTODEPLOY_KEA_SOCKET,
            $errno,
            $errstr,
            AUTODEPLOY_KEA_TIMEOUT
        );

        if ($socket === false) {
            throw new KeaException(
                'Could not open the Kea control socket: ' . ($errstr ?: "error $errno")
                . '. Check that the web server may write to ' . AUTODEPLOY_KEA_SOCKET . '.'
            );
        }

        try {
            stream_set_timeout($socket, AUTODEPLOY_KEA_TIMEOUT);

            $payload = ['command' => $command];
            if ($arguments !== []) {
                $payload['arguments'] = $arguments;
            }

            $encoded = json_encode($payload);
            if ($encoded === false) {
                throw new KeaException('Could not encode the command: ' . json_last_error_msg());
            }

            if (@fwrite($socket, $encoded) === false) {
                throw new KeaException("Could not send '$command' to Kea");
            }

            // Kea answers with one JSON document and then waits for the next
            // command, so read until it stops rather than until EOF.
            $response = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $response .= $chunk;

                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    throw new KeaException("Kea did not finish answering '$command' in time");
                }

                // A complete JSON document means the answer is in.
                if (json_decode($response, true) !== null) {
                    break;
                }
            }
        } finally {
            fclose($socket);
        }

        if (trim($response) === '') {
            throw new KeaException("Kea returned nothing for '$command'");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new KeaException("Could not parse Kea's answer to '$command'");
        }

        // Answers arrive as a list, one entry per service the command reached.
        $entry = isset($decoded['result']) ? $decoded : ($decoded[0] ?? null);
        if (!is_array($entry) || !isset($entry['result'])) {
            throw new KeaException("Kea's answer to '$command' has no result code");
        }

        // 0 success, 1 error, 2 unsupported, 3 empty (a successful "found
        // nothing"), 4 conflict.
        $result = (int)$entry['result'];
        if ($result !== 0 && $result !== 3) {
            throw new KeaException(
                "Kea refused '$command': " . ($entry['text'] ?? "result $result")
            );
        }

        return is_array($entry['arguments'] ?? null) ? $entry['arguments'] : [];
    }
}

if (!function_exists('keaGetConfig')) {
    /**
     * The running DHCPv4 configuration.
     *
     * @return array<string, mixed>
     * @throws KeaException
     */
    function keaGetConfig() {
        $config = keaCommand('config-get');

        if (!isset($config['Dhcp4']) || !is_array($config['Dhcp4'])) {
            throw new KeaException('Kea returned a configuration with no Dhcp4 section');
        }

        return $config;
    }
}

if (!function_exists('keaApplyConfig')) {
    /**
     * Validate, apply and persist a configuration.
     *
     * config-test first: a configuration Kea will not accept must be rejected
     * before it replaces the running one, not after. config-write last, so a
     * restart comes back to what is running rather than to what was there
     * before the change.
     *
     * @param array<string, mixed> $config Full configuration including Dhcp4
     * @throws KeaException
     */
    function keaApplyConfig(array $config) {
        keaCommand('config-test', $config);
        keaCommand('config-set', $config);

        try {
            keaCommand('config-write');
        } catch (KeaException $e) {
            // The running server already has the change; only persistence
            // failed. Say so precisely -- "it worked but will not survive a
            // restart" is a different problem to "it did not work".
            throw new KeaException(
                'The change is live but could not be written to disk, so it will be lost '
                . 'on restart: ' . $e->getMessage()
            );
        }
    }
}

if (!function_exists('keaUpdateNetwork')) {
    /**
     * Apply new provisioning network settings to the running server.
     *
     * Only the subnet is replaced. The client classes -- which decide whether
     * a machine gets iPXE, the ESXi loader over HTTP, or TFTP -- are left
     * exactly as they are, because they are a property of the boot chain and
     * not of the operator's address plan. Regenerating them from here is how
     * the file-based version kept reverting the boot method.
     *
     * @param array{start: string, end: string, netmask: string, gateway: string,
     *              dns: string, domain?: string, server_ip: string} $settings
     * @return array{success: bool, message: string, error: string}
     */
    function keaUpdateNetwork(array $settings) {
        $result = ['success' => false, 'message' => '', 'error' => ''];

        foreach (['start', 'end', 'netmask', 'gateway', 'server_ip'] as $key) {
            if (!isValidIpv4($settings[$key] ?? '')) {
                $result['error'] = "Invalid $key address";
                return $result;
            }
        }

        if (!isValidNetmask($settings['netmask'])) {
            $result['error'] = 'Invalid netmask';
            return $result;
        }

        $dns = [];
        foreach (explode(',', (string)($settings['dns'] ?? '')) as $server) {
            $server = trim($server);
            if ($server === '') {
                continue;
            }
            if (!isValidIpv4($server)) {
                $result['error'] = "Invalid DNS server: $server";
                return $result;
            }
            $dns[] = $server;
        }

        if ($dns === []) {
            $result['error'] = 'At least one DNS server is required';
            return $result;
        }

        $prefix = netmaskToPrefixLength($settings['netmask']);
        $network = long2ip(ip2long($settings['start']) & ip2long($settings['netmask']));

        // Everything must live in the same subnet, or the clients that get
        // this configuration cannot reach the server that sent it.
        $mask = ip2long($settings['netmask']);
        foreach (['end', 'gateway', 'server_ip'] as $key) {
            if ((ip2long($settings[$key]) & $mask) !== (ip2long($settings['start']) & $mask)) {
                $result['error'] = ucfirst(str_replace('_', ' ', $key)) . ' is outside the DHCP subnet';
                return $result;
            }
        }

        if (ip2long($settings['start']) > ip2long($settings['end'])) {
            $result['error'] = 'The pool starts above where it ends';
            return $result;
        }

        try {
            $config = keaGetConfig();

            $options = [
                ['name' => 'routers', 'data' => $settings['gateway']],
                ['name' => 'domain-name-servers', 'data' => implode(',', $dns)],
            ];
            if (!empty($settings['domain'])) {
                $options[] = ['name' => 'domain-name', 'data' => $settings['domain']];
            }

            // Keep the existing subnet's id and reservations: the id is what
            // Kea's own lease database refers to, and the reservations are
            // per-host pins an operator made.
            $existing = $config['Dhcp4']['subnet4'][0] ?? [];

            $config['Dhcp4']['subnet4'] = [[
                'id'           => (int)($existing['id'] ?? 1),
                'subnet'       => "$network/$prefix",
                'pools'        => [['pool' => $settings['start'] . ' - ' . $settings['end']]],
                'next-server'  => $settings['server_ip'],
                'option-data'  => $options,
                'reservations' => $existing['reservations'] ?? [],
            ]];

            keaApplyConfig($config);
        } catch (KeaException $e) {
            $result['error'] = $e->getMessage();
            logMessage('Kea update failed: ' . $e->getMessage(), 'ERROR');
            return $result;
        }

        logMessage(sprintf(
            'DHCP updated via the Kea API: %s/%d, pool %s - %s, gateway %s',
            $network,
            $prefix,
            $settings['start'],
            $settings['end'],
            $settings['gateway']
        ));

        $result['success'] = true;
        $result['message'] = "DHCP updated: $network/$prefix, pool {$settings['start']} - {$settings['end']}. "
            . 'Applied to the running server without a restart.';

        return $result;
    }
}

if (!function_exists('netmaskToPrefixLength')) {
    /**
     * @param string $netmask Dotted-quad netmask, already validated
     * @return int Prefix length
     */
    function netmaskToPrefixLength($netmask) {
        return substr_count(decbin(ip2long($netmask) & 0xFFFFFFFF), '1');
    }
}

if (!function_exists('keaStatus')) {
    /**
     * A summary for the settings screen.
     *
     * Never throws: this is called to render a page, and a DHCP server that
     * is down should show as down rather than as a stack trace.
     *
     * @return array{available: bool, version: string, subnet: string, pool: string, error: string}
     */
    function keaStatus() {
        $status = ['available' => false, 'version' => '', 'subnet' => '', 'pool' => '', 'error' => ''];

        try {
            $version = keaCommand('version-get');
            $status['version'] = (string)($version['extended'] ?? '');
            if ($status['version'] !== '') {
                // The extended string is several lines of build detail.
                $status['version'] = strtok($status['version'], "\n");
            }

            $subnet = keaGetConfig()['Dhcp4']['subnet4'][0] ?? [];
            $status['subnet'] = (string)($subnet['subnet'] ?? '');
            $status['pool'] = (string)($subnet['pools'][0]['pool'] ?? '');
            $status['available'] = true;
        } catch (KeaException $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }
}
