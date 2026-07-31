<?php
/**
 * The decisions the boot chain makes: who may boot, and what they are handed.
 *
 * boot.cfg lives on the installer media and names the kernel, the kernel
 * command line and the ~110 modules the bootloader has to load. Both the iPXE
 * script generator and (later) the UEFI HTTP boot path need to read it, so the
 * parsing lives here rather than inline in one endpoint. The approval gate is
 * here for the same reason and a stronger one: it had drifted into two copies.
 *
 * Nothing here touches the request or the log, and only bootCfgResolve() and
 * bootLoaderResolve() touch the filesystem -- they exist because *where* the
 * files are is the same question at four call sites, and answering it
 * differently at each is what C5 in docs/CODE-REVIEW-2026-07.md was. The rest
 * is pure, deliberately: it is the part of the boot chain worth testing, and a
 * function that reads a file and writes a log is a function nobody tests.
 */

// What bootGateGetDecision() can answer. Named constants rather than bare
// strings because the call sites compare against them and a typo in a string
// comparison against the gate fails open.
if (!defined('BOOT_GATE_ALLOW')) {
    define('BOOT_GATE_ALLOW', 'allow');
}
if (!defined('BOOT_GATE_DEPLOYED')) {
    define('BOOT_GATE_DEPLOYED', 'deployed');
}
if (!defined('BOOT_GATE_REFUSE')) {
    define('BOOT_GATE_REFUSE', 'refuse');
}

if (!function_exists('bootGateGetDecision')) {
    /**
     * Whether a host's deployment status may be handed something that installs.
     *
     * This is the approval gate. It decides nothing less than "does this
     * machine get wiped and reinstalled", and until now it existed as two
     * inline copies of the same condition, in www/boot.cfg.php and
     * www/boot.ipxe.php, that had to agree without anything making them. If
     * one of them ever admitted a status the other refused, a host awaiting
     * approval would be handed an installer by whichever endpoint it reached
     * first. One function, one test, two callers.
     *
     * The three answers exist because the endpoints legitimately differ in
     * what they do with a finished host: boot.ipxe.php tells it to boot from
     * local disk (a reinstall on every reboot is the failure this prevents),
     * while boot.cfg.php has nothing to say to it and refuses. Both refuse
     * everything that is not approved, which is the part that matters.
     *
     * Matching is exact, deliberately. Anything that normalises the value
     * first -- trim(), strtolower() -- widens the set of strings that open the
     * gate, and a gate that opens for " Approved\n" is a gate that opens for
     * whatever else finds its way into the column.
     *
     * @param mixed $status deployment_status as stored, which may be missing
     *                      or, from an older inventory, not a string at all
     * @return string BOOT_GATE_ALLOW, BOOT_GATE_DEPLOYED or BOOT_GATE_REFUSE
     */
    function bootGateGetDecision($status) {
        if (!is_string($status)) {
            return BOOT_GATE_REFUSE;
        }

        // 'deploying' is here because a host reboots partway through an ESXi
        // install and has to be able to fetch the same files again; the status
        // is only set after the gate has already admitted it once.
        if ($status === 'approved' || $status === 'deploying') {
            return BOOT_GATE_ALLOW;
        }

        if ($status === 'deployed') {
            return BOOT_GATE_DEPLOYED;
        }

        return BOOT_GATE_REFUSE;
    }
}

if (!function_exists('bootCfgCandidates')) {
    /**
     * Where boot.cfg can sit in an extracted installation medium.
     *
     * ESXi ships it at the root and again under efi/boot/, and which of those
     * survives depends on how the medium was extracted -- a case-insensitive
     * unpack yields BOOT.CFG. The list lives in one place because a version
     * accepted at upload has to be the same set of versions that can boot: it
     * used to be spelled four different ways, so a medium carrying only
     * efi/boot/boot.cfg passed imageLooksBootable(), booted through
     * boot.cfg.php, and was simultaneously reported as not installed by the
     * admin UI and refused by boot.ipxe.php.
     *
     * Root first: that is the copy VMware intends to be read, and the one the
     * others are a fallback for.
     *
     * @param string $imageDir Extracted image directory, without a trailing slash
     * @return string[] Absolute paths, most likely first
     */
    function bootCfgCandidates($imageDir) {
        $imageDir = rtrim((string)$imageDir, '/');

        return [
            $imageDir . '/boot.cfg',
            $imageDir . '/BOOT.CFG',
            $imageDir . '/efi/boot/boot.cfg',
        ];
    }
}

if (!function_exists('bootLoaderCandidates')) {
    /**
     * Where the UEFI loader (mboot) can sit in an extracted medium.
     *
     * Same question as bootCfgCandidates(), same reason for one answer: this
     * list was written out twice, in boot.ipxe.php and mboot.efi.php.
     *
     * Relative, unlike the boot.cfg candidates, because one of the two callers
     * turns the answer into a URL under the image's HTTP prefix and the other
     * into a path under its directory. The same suffix serves both.
     *
     * @return string[] Paths relative to the image directory, most likely first
     */
    function bootLoaderCandidates() {
        return [
            '/efi/boot/bootx64.efi',
            '/mboot.efi',
            '/EFI/BOOT/BOOTX64.EFI',
        ];
    }
}

if (!function_exists('bootCfgResolve')) {
    /**
     * The boot.cfg an extracted medium actually has, or null.
     *
     * @param string $imageDir Extracted image directory
     * @return string|null Path to the first candidate that exists
     */
    function bootCfgResolve($imageDir) {
        foreach (bootCfgCandidates($imageDir) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('bootLoaderResolve')) {
    /**
     * The UEFI loader an extracted medium actually has, or null.
     *
     * @param string $imageDir Extracted image directory
     * @return string|null The relative path of the first candidate that
     *                     exists, for the caller to join to a URL or a path
     */
    function bootLoaderResolve($imageDir) {
        $imageDir = rtrim((string)$imageDir, '/');

        foreach (bootLoaderCandidates() as $candidate) {
            if (is_file($imageDir . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

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
