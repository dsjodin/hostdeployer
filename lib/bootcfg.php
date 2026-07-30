<?php
/**
 * Parsing of the ESXi installer's boot.cfg.
 *
 * The file lives on the installer media and names the kernel, the kernel
 * command line and the ~110 modules the bootloader has to load. Both the iPXE
 * script generator and (later) the UEFI HTTP boot path need to read it, so the
 * parsing lives here rather than inline in one endpoint.
 *
 * Nothing in this file touches the filesystem, the request or the log. That is
 * deliberate: it is the part of the boot chain that is worth testing, and a
 * function that reads a file and writes a log is a function nobody tests.
 */

if (!function_exists('parseBootCfg')) {
    /**
     * Parse the contents of an ESXi boot.cfg.
     *
     * Unknown keys are ignored rather than rejected -- VMware adds keys
     * between releases (bootstate, build, updated, title, timeout) and none of
     * them change how the installer is booted over the network.
     *
     * @param string $contents Raw contents of a boot.cfg
     * @return array{kernel: string, kernelopt: string, modules: string[], prefix: string}
     */
    function parseBootCfg($contents) {
        $result = [
            'kernel'    => '',
            'kernelopt' => '',
            'modules'   => [],
            'prefix'    => '',
        ];

        foreach (preg_split('/\r\n|\r|\n/', (string)$contents) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = rtrim(substr($line, 0, $eq));
            $value = ltrim(substr($line, $eq + 1));

            switch ($key) {
                case 'kernel':
                    $result['kernel'] = $value;
                    break;
                // VMware ships "kernelopt"; accept "kernelopts" too since
                // hand-edited boot.cfg files in this repo have used both
                // spellings.
                case 'kernelopt':
                case 'kernelopts':
                    $result['kernelopt'] = $value;
                    break;
                case 'modules':
                    $result['modules'] = array_values(
                        array_filter(array_map('trim', explode('---', $value)), static fn($m) => $m !== '')
                    );
                    break;
                case 'prefix':
                    $result['prefix'] = $value;
                    break;
            }
        }

        return $result;
    }
}

if (!function_exists('bootCfgIsUsable')) {
    /**
     * A boot.cfg without a kernel or without modules cannot boot anything.
     *
     * This is the failure behind "installer starts but finds no modules" in
     * docs/bootchain.md, so it is worth naming rather than leaving as two
     * conditions at the call site.
     *
     * @param array{kernel: string, modules: string[]} $parsed Result of parseBootCfg()
     * @return bool True when the file names both a kernel and at least one module
     */
    function bootCfgIsUsable(array $parsed) {
        return $parsed['kernel'] !== '' && $parsed['modules'] !== [];
    }
}

if (!function_exists('renderBootCfg')) {
    /**
     * Rewrite an ISO's boot.cfg for one host.
     *
     * Ported from via_go's internal/boot package, which arrived at these rules
     * the hard way. What the shipped file needs before mboot can use it over
     * the network:
     *
     *  - Path separators are stripped. The loader fetches every file from one
     *    directory whatever the ISO layout was, so "/b.b00" has to become
     *    "b.b00".
     *  - cdromBoot is removed. It makes the installer look for its media on a
     *    CD-ROM that is not there.
     *  - The kernel command line gains the kickstart URL and the host's
     *    addressing, so the installer comes up on the right network without a
     *    DHCP lease of its own.
     *  - prefix= is where the loader fetches from. It is empty in the shipped
     *    file and the value is appended to it.
     *
     * The order is load-bearing: separators must be stripped before any URL is
     * added, or the slashes in those URLs are stripped too.
     *
     * @param string               $source Contents of the ISO's boot.cfg
     * @param array<string, mixed> $params prefix, ks_url, mac, ip, netmask,
     *                                     gateway, vlan, allow_legacy_cpu
     * @return string The rewritten file
     */
    function renderBootCfg($source, array $params) {
        $out = str_replace('/', '', (string)$source);
        $out = str_replace('cdromBoot', '', $out);

        $ksUrl = (string)($params['ks_url'] ?? '');
        if ($ksUrl !== '') {
            $out = bootCfgAppendKernelOpt($out, ' ks=' . $ksUrl);
        }

        // netdevice pins the installer to the interface that booted; without
        // it a multi-homed server may bring up the wrong one and be
        // unreachable at the address the operator configured.
        $mac = formatMac($params['mac'] ?? '');
        if ($mac !== '' && ($params['ip'] ?? '') !== '') {
            $out = bootCfgAppendKernelOpt($out, sprintf(
                ' netdevice=%s ip=%s netmask=%s gateway=%s',
                $mac,
                $params['ip'],
                $params['netmask'] ?? '255.255.255.0',
                $params['gateway'] ?? ''
            ));
        }

        $vlan = (string)($params['vlan'] ?? '');
        if ($vlan !== '' && $vlan !== '0') {
            $out = bootCfgAppendKernelOpt($out, ' vlanid=' . $vlan);
        }

        if (!empty($params['allow_legacy_cpu'])) {
            // Matches --forceunsupportedinstall in the kickstart templates.
            // Set in one place or the other and the install fails halfway.
            $out = bootCfgAppendKernelOpt($out, ' allowLegacyCPU=true');
        }

        $prefix = (string)($params['prefix'] ?? '');
        if ($prefix !== '') {
            $out = preg_replace('/^prefix=.*$/m', 'prefix=' . $prefix, $out, 1) ?? $out;
        }

        return $out;
    }
}

if (!function_exists('bootCfgAppendKernelOpt')) {
    /**
     * Append to the kernelopt line, leaving the rest of the file alone.
     *
     * A file with no kernelopt line is returned unchanged rather than growing
     * one: the absence means the media is not what we think it is, and adding
     * a line would hide that.
     *
     * @param string $source     boot.cfg contents
     * @param string $additional Text to append, including its leading space
     * @return string
     */
    function bootCfgAppendKernelOpt($source, $additional) {
        return preg_replace(
            '/^(kernelopts?=.*)$/m',
            '$1' . str_replace('$', '\\$', $additional),
            (string)$source,
            1
        ) ?? $source;
    }
}

if (!function_exists('bootCfgHTTPPrefix')) {
    /**
     * The prefix for a host booting over HTTP or HTTPS.
     *
     * The loader fetches the kernel and modules back over the same transport,
     * so it needs an absolute URL rather than a directory name.
     *
     * @param string $baseUrl Deployment server base URL, no trailing slash
     * @param string $version ESXi version, already validated
     * @return string
     */
    function bootCfgHTTPPrefix($baseUrl, $version) {
        return rtrim((string)$baseUrl, '/') . '/esxi/' . $version;
    }
}

if (!function_exists('stripKickstartOption')) {
    /**
     * Remove any ks= the packaged boot.cfg carries.
     *
     * The deployment server appends its own, pointing at the dynamic
     * generator; leaving the media's copy in place would give the installer
     * two and it uses the first.
     *
     * @param string $kernelopt Kernel command line from boot.cfg
     * @return string The command line without a ks= parameter
     */
    function stripKickstartOption($kernelopt) {
        return trim((string)preg_replace('/\bks=\S+/', '', (string)$kernelopt));
    }
}
