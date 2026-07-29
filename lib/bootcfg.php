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
                        array_filter(array_map('trim', explode('---', $value)), 'strlen')
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
        return ($parsed['kernel'] ?? '') !== '' && !empty($parsed['modules']);
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
