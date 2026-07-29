<?php
/**
 * Common utility functions for ESXi Autodeploy.
 *
 * This file is the single source of truth for shared helpers. The admin
 * dashboard and the boot/kickstart endpoints both include it, so every
 * function here is guarded with function_exists() to stay tolerant of
 * legacy include orders.
 */

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

if (!defined('AUTODEPLOY_ROOT')) {
    // Allow the deployment root to be overridden (tests, alternative prefixes).
    define('AUTODEPLOY_ROOT', getenv('AUTODEPLOY_ROOT') ?: '/srv/autodeploy');
}
if (!defined('AUTODEPLOY_CONFIG_DIR')) {
    define('AUTODEPLOY_CONFIG_DIR', AUTODEPLOY_ROOT . '/config');
}
if (!defined('AUTODEPLOY_LOG_DIR')) {
    define('AUTODEPLOY_LOG_DIR', AUTODEPLOY_ROOT . '/logs');
}
if (!defined('AUTODEPLOY_GLOBAL_CONFIG')) {
    define('AUTODEPLOY_GLOBAL_CONFIG', AUTODEPLOY_CONFIG_DIR . '/global_config.json');
}
if (!defined('AUTODEPLOY_HOSTS_CONFIG')) {
    define('AUTODEPLOY_HOSTS_CONFIG', AUTODEPLOY_CONFIG_DIR . '/hosts.json');
}
if (!defined('AUTODEPLOY_CREDENTIALS')) {
    define('AUTODEPLOY_CREDENTIALS', AUTODEPLOY_CONFIG_DIR . '/credentials.json');
}

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

if (!function_exists('logMessage')) {
    /**
     * Append a message to a log file.
     *
     * Newlines in the message are collapsed so attacker-controlled values
     * (MAC addresses, hostnames) cannot forge extra log records.
     *
     * @param string      $message Message to log
     * @param string      $level   Log level (INFO, WARNING, ERROR, DEBUG)
     * @param string|null $logFile Absolute path to log file (optional)
     */
    function logMessage($message, $level = 'INFO', $logFile = null) {
        if ($logFile === null) {
            $scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown', '.php');
            // Never let the script name escape the log directory.
            $scriptName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scriptName);
            $logFile = AUTODEPLOY_LOG_DIR . "/{$scriptName}.log";
        }

        $level = preg_replace('/[^A-Z]/', '', strtoupper((string)$level)) ?: 'INFO';
        $message = str_replace(["\r", "\n"], ' ', (string)$message);

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . "] [$level] $message\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

// ---------------------------------------------------------------------------
// Output escaping
// ---------------------------------------------------------------------------

if (!function_exists('h')) {
    /**
     * Escape a value for output in an HTML text node or attribute.
     *
     * ENT_QUOTES is explicit: several templates interpolate host data into
     * single-quoted attribute values, and the htmlspecialchars() default only
     * escapes single quotes on PHP 8.1 and newer.
     *
     * @param mixed $value Value to escape
     * @return string Escaped string
     */
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('jsValue')) {
    /**
     * Encode a value as a JSON literal safe to embed in an HTML attribute.
     *
     * @param mixed $value Value to encode
     * @return string Escaped JSON
     */
    function jsValue($value) {
        return htmlspecialchars(
            (string)json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

// ---------------------------------------------------------------------------
// MAC address handling
// ---------------------------------------------------------------------------

if (!function_exists('formatMac')) {
    /**
     * Normalise a MAC address to lower-case colon-separated form.
     *
     * Accepts any separator (colon, dash, dot, space) and returns an empty
     * string when the input is not a valid 48-bit MAC. Callers must treat an
     * empty result as "invalid input" -- earlier versions happily returned
     * garbage such as "zz:zz" for non-hex input.
     *
     * @param string $mac MAC address in any format
     * @return string Normalised MAC (xx:xx:xx:xx:xx:xx) or '' when invalid
     */
    function formatMac($mac) {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$mac));

        if (strlen($hex) !== 12) {
            return '';
        }

        return implode(':', str_split($hex, 2));
    }
}

if (!function_exists('isValidMac')) {
    /**
     * @param string $mac MAC address in any format
     * @return bool True when the value is a well-formed MAC address
     */
    function isValidMac($mac) {
        return formatMac($mac) !== '';
    }
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

if (!function_exists('isValidIp')) {
    /**
     * @param string $ip IP address to validate
     * @return bool True when $ip is a valid IPv4/IPv6 address
     */
    function isValidIp($ip) {
        return filter_var((string)$ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('isValidIpv4')) {
    function isValidIpv4($ip) {
        return filter_var((string)$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}

if (!function_exists('isValidNetmask')) {
    /**
     * @param string $mask Dotted-quad netmask
     * @return bool True when $mask is a valid contiguous IPv4 netmask
     */
    function isValidNetmask($mask) {
        if (!isValidIpv4($mask)) {
            return false;
        }

        // A valid mask is a run of ones followed by a run of zeros, so the
        // bitwise inverse must be of the form 2^k - 1.
        $long = ip2long($mask) & 0xFFFFFFFF;
        $inverted = (~$long) & 0xFFFFFFFF;

        return (($inverted + 1) & $inverted) === 0;
    }
}

if (!function_exists('isValidHostname')) {
    /**
     * @param string $name Hostname or FQDN label sequence
     * @return bool True when the name is a syntactically valid hostname
     */
    function isValidHostname($name) {
        $name = (string)$name;
        if ($name === '' || strlen($name) > 253) {
            return false;
        }
        return (bool)preg_match(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
            $name
        );
    }
}

if (!function_exists('isValidVlanId')) {
    function isValidVlanId($vlan) {
        return is_numeric($vlan) && (int)$vlan >= 0 && (int)$vlan <= 4094;
    }
}

if (!function_exists('extractHostnameFromFQDN')) {
    /**
     * Return the first label of an FQDN.
     *
     * This function was referenced by the host editor but never defined,
     * which produced a fatal error whenever a host was saved without an
     * explicit hostname.
     *
     * @param string $fqdn Fully qualified domain name
     * @return string The short hostname
     */
    function extractHostnameFromFQDN($fqdn) {
        $fqdn = trim((string)$fqdn);
        if ($fqdn === '') {
            return '';
        }
        $parts = explode('.', $fqdn);
        return $parts[0];
    }
}

// ---------------------------------------------------------------------------
// Safe path handling
// ---------------------------------------------------------------------------

if (!function_exists('safePathJoin')) {
    /**
     * Join a user-supplied relative name onto a trusted base directory.
     *
     * Rejects absolute paths, traversal sequences and anything that resolves
     * outside $baseDir. Returns null when the input is not acceptable.
     *
     * @param string $baseDir      Trusted base directory
     * @param string $userPath     Untrusted file name (may contain sub-dirs)
     * @param bool   $mustExist    Require the resulting path to exist
     * @return string|null Absolute path inside $baseDir, or null
     */
    function safePathJoin($baseDir, $userPath, $mustExist = false) {
        $userPath = (string)$userPath;

        if ($userPath === '' || strpos($userPath, "\0") !== false) {
            return null;
        }
        // No absolute paths, no traversal, no sneaky separators.
        if ($userPath[0] === '/' || $userPath[0] === '\\') {
            return null;
        }
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $userPath)) {
            return null;
        }

        $realBase = realpath($baseDir);
        if ($realBase === false) {
            return null;
        }

        $candidate = $realBase . '/' . ltrim($userPath, '/');
        $real = realpath($candidate);

        if ($real === false) {
            if ($mustExist) {
                return null;
            }
            // File does not exist yet (create/save): validate the parent dir.
            $parent = realpath(dirname($candidate));
            if ($parent === false || strncmp($parent . '/', $realBase . '/', strlen($realBase) + 1) !== 0) {
                return null;
            }
            return $parent . '/' . basename($candidate);
        }

        if (strncmp($real . '/', $realBase . '/', strlen($realBase) + 1) !== 0) {
            return null;
        }

        return $real;
    }
}

// ---------------------------------------------------------------------------
// JSON configuration I/O
// ---------------------------------------------------------------------------

if (!function_exists('loadJsonConfig')) {
    /**
     * Load and decode a JSON config file under a shared lock.
     *
     * @param string $path Path to JSON config file
     * @return array|null Config array or null on failure
     */
    function loadJsonConfig($path) {
        if (!is_file($path)) {
            logMessage("Config file not found: $path", 'ERROR');
            return null;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            logMessage("Failed to open config file: $path", 'ERROR');
            return null;
        }

        $content = '';
        if (flock($handle, LOCK_SH)) {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    break;
                }
                $content .= $chunk;
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        if ($content === '') {
            logMessage("Config file is empty: $path", 'ERROR');
            return null;
        }

        $config = json_decode($content, true);
        if (!is_array($config)) {
            logMessage('Failed to parse JSON config ' . $path . ': ' . json_last_error_msg(), 'ERROR');
            return null;
        }

        return $config;
    }
}

if (!function_exists('saveJsonConfig')) {
    /**
     * Encode and write a JSON config file atomically.
     *
     * The previous implementation wrote in place, so a crash or a concurrent
     * reader could observe (or persist) a truncated hosts.json. We now write
     * to a temporary file in the same directory and rename() over the target,
     * which is atomic on POSIX filesystems.
     *
     * @param string $path   Path to JSON config file
     * @param array  $config Config array
     * @return bool True on success
     */
    function saveJsonConfig($path, $config) {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            logMessage('Failed to encode config for ' . $path . ': ' . json_last_error_msg(), 'ERROR');
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            logMessage("Failed to create config directory: $dir", 'ERROR');
            return false;
        }

        $tmp = @tempnam($dir, '.tmp-' . basename($path) . '-');
        if ($tmp === false) {
            logMessage("Failed to create temporary file in $dir", 'ERROR');
            return false;
        }

        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            logMessage("Failed to write config file: $path", 'ERROR');
            return false;
        }

        // Preserve permissions of the existing file, otherwise use a
        // restrictive default (these files hold credentials).
        $mode = is_file($path) ? (fileperms($path) & 0777) : 0640;
        @chmod($tmp, $mode);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            logMessage("Failed to replace config file: $path", 'ERROR');
            return false;
        }

        return true;
    }
}

if (!function_exists('updateJsonConfig')) {
    /**
     * Read-modify-write a JSON config file while holding an exclusive lock.
     *
     * Every caller that mutated hosts.json previously did an unsynchronised
     * load/modify/save, so two servers PXE-booting at the same time could
     * silently drop one another's status update. Route all mutations through
     * this helper instead.
     *
     * @param string   $path    Path to the JSON config file
     * @param callable $mutator function(array &$config): bool - return false to abort
     * @return bool True when the file was updated and written
     */
    function updateJsonConfig($path, callable $mutator) {
        $lockPath = $path . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            logMessage("Failed to open lock file: $lockPath", 'ERROR');
            return false;
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            logMessage("Failed to acquire lock: $lockPath", 'ERROR');
            return false;
        }

        try {
            $config = loadJsonConfig($path);
            if ($config === null) {
                return false;
            }

            if ($mutator($config) === false) {
                return false;
            }

            return saveJsonConfig($path, $config);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

// ---------------------------------------------------------------------------
// Credentials
// ---------------------------------------------------------------------------

if (!function_exists('loadSecureCredentials')) {
    /**
     * Load credentials, optionally narrowed to a type and a specific host.
     *
     * @param string|null $credentialType Type of credential (ilo, esxi, db)
     * @param string|null $macAddress     MAC for host-specific overrides
     * @return array|null Credentials array or null when not found
     */
    function loadSecureCredentials($credentialType = null, $macAddress = null) {
        $credentials = loadJsonConfig(AUTODEPLOY_CREDENTIALS);
        if ($credentials === null) {
            return null;
        }

        if ($credentialType === null) {
            return $credentials;
        }

        if (!isset($credentials[$credentialType]) || !is_array($credentials[$credentialType])) {
            return null;
        }

        $section = $credentials[$credentialType];

        if ($macAddress !== null) {
            $macFormatted = formatMac($macAddress);
            if ($macFormatted !== '' && isset($section['hosts'][$macFormatted]) && is_array($section['hosts'][$macFormatted])) {
                return array_merge($section, $section['hosts'][$macFormatted]);
            }
        }

        return $section;
    }
}

if (!function_exists('saveSecureCredentials')) {
    /**
     * Persist the credentials file with restrictive permissions.
     *
     * @param array $credentials Full credentials structure
     * @return bool True on success
     */
    function saveSecureCredentials(array $credentials) {
        $ok = saveJsonConfig(AUTODEPLOY_CREDENTIALS, $credentials);
        if ($ok) {
            @chmod(AUTODEPLOY_CREDENTIALS, 0640);
        }
        return $ok;
    }
}

if (!function_exists('generateEsxiPasswordHash')) {
    /**
     * Generate an ESXi-compatible SHA-512 crypt hash.
     *
     * @param string $password Plain text password
     * @return string SHA-512 crypt hash
     */
    function generateEsxiPasswordHash($password) {
        // str_shuffle() is not a CSPRNG; use random_int() for the salt.
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 16; $i++) {
            $salt .= $alphabet[random_int(0, $max)];
        }

        return crypt($password, '$6$' . $salt . '$');
    }
}

if (!function_exists('generateRandomPassword')) {
    /**
     * @param int $length Length of the password
     * @return string Cryptographically random password
     */
    function generateRandomPassword($length = 16) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}

// ---------------------------------------------------------------------------
// Host lookup helpers
// ---------------------------------------------------------------------------

if (!function_exists('findHostByMac')) {
    /**
     * @param string $macAddress  MAC address to search for
     * @param array  $hostsConfig Hosts configuration array
     * @return array|null Host entry or null when not found
     */
    function findHostByMac($macAddress, $hostsConfig) {
        if (!is_array($hostsConfig) || !isset($hostsConfig['hosts']) || !is_array($hostsConfig['hosts'])) {
            logMessage('Invalid hosts configuration structure', 'ERROR');
            return null;
        }

        $macAddress = formatMac($macAddress);
        if ($macAddress === '') {
            return null;
        }

        foreach ($hostsConfig['hosts'] as $host) {
            if (!isset($host['mac_address'])) {
                continue;
            }
            if (formatMac($host['mac_address']) === $macAddress) {
                return $host;
            }
            // Servers report the MAC of whichever NIC booted; match secondaries too.
            foreach (($host['additional_macs'] ?? []) as $extra) {
                if (formatMac($extra) === $macAddress) {
                    return $host;
                }
            }
        }

        return null;
    }
}

if (!function_exists('hostMatchesMac')) {
    /**
     * @param array  $host Host entry
     * @param string $mac  Normalised MAC address
     * @return bool True when the host owns this MAC (primary or additional)
     */
    function hostMatchesMac(array $host, $mac) {
        if (isset($host['mac_address']) && formatMac($host['mac_address']) === $mac) {
            return true;
        }
        foreach (($host['additional_macs'] ?? []) as $extra) {
            if (formatMac($extra) === $mac) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('updateHostByMac')) {
    /**
     * Merge new fields into a host entry, atomically and under a lock.
     *
     * @param string      $mac        MAC address of the host to update
     * @param array       $newData    Fields to merge into the host entry
     * @param string|null $configPath Path to hosts.json (defaults to the standard location)
     * @return bool True when the host was found and the file written
     */
    function updateHostByMac($mac, array $newData, $configPath = null) {
        $configPath = $configPath ?: AUTODEPLOY_HOSTS_CONFIG;
        $mac = formatMac($mac);
        if ($mac === '') {
            return false;
        }

        $found = false;
        $ok = updateJsonConfig($configPath, function (array &$config) use ($mac, $newData, &$found) {
            foreach ($config['hosts'] as &$host) {
                if (hostMatchesMac($host, $mac)) {
                    $host = array_merge($host, $newData);
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

if (!function_exists('updateHostLastSeen')) {
    /**
     * @param string      $macAddress   MAC address of the host
     * @param string|null $serialNumber Optional serial number to record
     * @return bool True on success
     */
    function updateHostLastSeen($macAddress, $serialNumber = null) {
        $data = ['last_seen' => date('Y-m-d H:i:s')];

        if ($serialNumber !== null && $serialNumber !== '') {
            // Serial numbers arrive from the installer; keep them printable.
            $data['serial_number'] = preg_replace('/[^\x20-\x7E]/', '', (string)$serialNumber);
        }

        return updateHostByMac($macAddress, $data);
    }
}

// ---------------------------------------------------------------------------
// Templating
// ---------------------------------------------------------------------------

if (!function_exists('processConditionals')) {
    /**
     * Expand {{#VAR}}...{{/VAR}}, {{IF VAR}}...{{ELSE}}...{{ENDIF}} blocks.
     *
     * IF/ELSE is handled before plain IF, otherwise the plain-IF pattern
     * consumes the ELSE branch and emits it unconditionally.
     *
     * @param string $template  Template with conditionals
     * @param array  $variables Variables used to evaluate the conditionals
     * @return string Processed template
     */
    function processConditionals($template, array $variables) {
        $evaluate = static function ($name) use ($variables) {
            return !empty($variables[$name]);
        };

        // {{IF VAR}}...{{ELSE}}...{{ENDIF}} must run first.
        $template = preg_replace_callback(
            '/\{\{IF\s+([A-Z0-9_]+)\}\}(.*?)\{\{ELSE\}\}(.*?)\{\{ENDIF\}\}/s',
            static function ($m) use ($evaluate) {
                return $evaluate($m[1]) ? $m[2] : $m[3];
            },
            $template
        );

        // {{IF VAR}}...{{ENDIF}}
        $template = preg_replace_callback(
            '/\{\{IF\s+([A-Z0-9_]+)\}\}(.*?)\{\{ENDIF\}\}/s',
            static function ($m) use ($evaluate) {
                return $evaluate($m[1]) ? $m[2] : '';
            },
            $template
        );

        // {{#VAR}}...{{/VAR}}
        $template = preg_replace_callback(
            '/\{\{#([A-Za-z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/s',
            static function ($m) use ($evaluate) {
                return $evaluate($m[1]) ? $m[2] : '';
            },
            $template
        );

        return $template;
    }
}

if (!function_exists('renderTemplate')) {
    /**
     * Render a template by expanding conditionals and replacing {{VAR}} tokens.
     *
     * @param string $template  Template content
     * @param array  $variables Key/value pairs
     * @return string Rendered template
     */
    function renderTemplate($template, array $variables) {
        $template = processConditionals($template, $variables);

        $search = [];
        $replace = [];
        foreach ($variables as $key => $value) {
            if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
                continue;
            }
            $search[] = '{{' . $key . '}}';
            $replace[] = (string)$value;
        }

        // Single pass so that a replacement value can never be re-expanded.
        return str_replace($search, $replace, $template);
    }
}

// ---------------------------------------------------------------------------
// Client identification
// ---------------------------------------------------------------------------

if (!function_exists('getClientMacAddress')) {
    /**
     * Determine the MAC address of the requesting client.
     *
     * @return string|null Normalised MAC address, or null when unknown
     */
    function getClientMacAddress() {
        if (!empty($_GET['mac'])) {
            $mac = formatMac($_GET['mac']);
            if ($mac !== '') {
                return $mac;
            }
        }

        if (isset($_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0'])) {
            $header = $_SERVER['HTTP_X_RHN_PROVISIONING_MAC_0'];
            // Format is "<iface> <mac>"; take the last whitespace-separated field.
            $parts = preg_split('/\s+/', trim($header));
            $mac = formatMac(end($parts));
            if ($mac !== '') {
                return $mac;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (isValidIp($remoteAddr)) {
            $mac = lookupMacViaArp($remoteAddr);
            if ($mac !== null) {
                return $mac;
            }
        }

        return null;
    }
}

if (!function_exists('lookupMacViaArp')) {
    /**
     * Resolve an IP to a MAC using the local ARP table.
     *
     * Reads /proc/net/arp directly instead of shelling out to arp(8), which
     * removes a command-injection surface and a process spawn per request.
     *
     * @param string $ip IPv4 address
     * @return string|null Normalised MAC or null
     */
    function lookupMacViaArp($ip) {
        if (!isValidIpv4($ip)) {
            return null;
        }

        $arpTable = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($arpTable === false) {
            return null;
        }

        foreach ($arpTable as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) < 4 || $fields[0] !== $ip) {
                continue;
            }
            $mac = formatMac($fields[3]);
            if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                return $mac;
            }
        }

        return null;
    }
}

// ---------------------------------------------------------------------------
// Hardware helpers
// ---------------------------------------------------------------------------

if (!function_exists('disableSecureBoot')) {
    /**
     * @param string $macAddress MAC address of the host
     * @return bool True when the helper script reported success
     */
    function disableSecureBoot($macAddress) {
        return runSecureBootManager($macAddress, false)['success'];
    }
}

if (!function_exists('runSecureBootManager')) {
    /**
     * Invoke scripts/secure_boot_manager.py for a host.
     *
     * @param string $macAddress MAC address of the host
     * @param bool   $enable     True to enable secure boot, false to disable
     * @return array{success: bool, output: string}
     */
    function runSecureBootManager($macAddress, $enable) {
        $mac = formatMac($macAddress);
        if ($mac === '') {
            return ['success' => false, 'output' => 'Invalid MAC address'];
        }

        $action = $enable ? 'enable' : 'disable';
        $command = sprintf(
            'python3 %s --mac %s --action %s 2>&1',
            escapeshellarg(AUTODEPLOY_ROOT . '/scripts/secure_boot_manager.py'),
            escapeshellarg($mac),
            escapeshellarg($action)
        );

        $output = [];
        $returnCode = 1;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode === 0) {
            logMessage("Secure boot $action succeeded for $mac");
        } else {
            logMessage("Secure boot $action failed for $mac: " . str_replace("\n", ' | ', $outputStr), 'ERROR');
        }

        return ['success' => $returnCode === 0, 'output' => $outputStr];
    }
}

// ---------------------------------------------------------------------------
// Misc formatting
// ---------------------------------------------------------------------------

if (!function_exists('getReadableFileSize')) {
    /**
     * @param int $bytes     Size in bytes
     * @param int $precision Decimal precision
     * @return string Human-readable size
     */
    function getReadableFileSize($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max((float)$bytes, 0);
        $pow = $bytes > 0 ? (int)floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
