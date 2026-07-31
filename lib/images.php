<?php
/**
 * ESXi installer media: upload, verification, extraction and registration.
 *
 * Installing a new ESXi release used to mean shell access to the deployment
 * server: mount the ISO, copy its contents into esxi/<version>/, unmount, and
 * add the version to global_config.json by hand. Four steps, none of them
 * checked, and a typo in any of them produces a host that boots the installer
 * and then cannot find its modules.
 *
 * There is no ISO reader in PHP without pulling in a dependency, so extraction
 * shells out. The command is chosen by probing rather than hardcoded: the tool
 * that is present differs between distributions, and failing with "bsdtar: not
 * found" on a machine that has 7z installed would be an unnecessary dead end.
 */

require_once __DIR__ . '/utils.php';

if (!defined('AUTODEPLOY_IMAGE_DIR')) {
    define('AUTODEPLOY_IMAGE_DIR', AUTODEPLOY_ROOT . '/esxi');
}

if (!function_exists('imageExtractorCandidates')) {
    /**
     * Extraction commands, in order of preference.
     *
     * %s placeholders are the ISO path and the target directory, in that
     * order; both are escaped by the caller.
     *
     * bsdtar first because libarchive reads ISO9660 and UDF alike and is the
     * most commonly installed. 7z handles the same formats. xorriso is the
     * fallback, being the one most likely present on a machine that already
     * works with images.
     *
     * @return array<string, string> Command name => sprintf template
     */
    function imageExtractorCandidates() {
        return [
            'bsdtar' => 'bsdtar -x -f %s -C %s',
            '7z'     => '7z x -y -o%2$s %1$s',
            '7za'    => '7za x -y -o%2$s %1$s',
            'xorriso' => 'xorriso -osirrox on -indev %s -extract / %s',
        ];
    }
}

if (!function_exists('imageAvailableExtractor')) {
    /**
     * The first extraction command available on this machine.
     *
     * @return string|null The command name, or null when none is installed
     */
    function imageAvailableExtractor() {
        foreach (array_keys(imageExtractorCandidates()) as $command) {
            // command -v rather than which: it is a shell builtin, so it works
            // on a minimal system where which is not installed.
            exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
            if ($code === 0) {
                return $command;
            }
        }

        return null;
    }
}

if (!function_exists('imageIsValidVersionName')) {
    /**
     * Whether a version name is safe to use as a path segment and a URL.
     *
     * The same pattern www/boot.ipxe.php enforces before building the image
     * URL. Kept identical deliberately: a name that passes here and fails
     * there would be an image that uploads and never boots.
     *
     * @param string $version Proposed version name
     * @return bool
     */
    function imageIsValidVersionName($version) {
        $version = (string)$version;

        if ($version === '' || strlen($version) > 64) {
            return false;
        }

        // A leading dot would make a hidden directory, and "." and ".." are
        // traversal even though the character class alone permits them.
        if ($version[0] === '.') {
            return false;
        }

        return (bool)preg_match('/^[A-Za-z0-9._-]+$/', $version);
    }
}

if (!function_exists('imageVerifyHash')) {
    /**
     * Compare a file against an expected SHA-256 digest.
     *
     * Streamed by hash_file() rather than read into memory: an ESXi ISO is
     * several gigabytes and php-fpm has a memory limit.
     *
     * @param string $path     File to check
     * @param string $expected Expected digest, hex, case insensitive
     * @return bool True when the digest matches
     */
    function imageVerifyHash($path, $expected) {
        $expected = strtolower(trim((string)$expected));
        if ($expected === '' || !preg_match('/^[0-9a-f]{64}$/', $expected)) {
            return false;
        }

        $actual = @hash_file('sha256', $path);
        if ($actual === false) {
            return false;
        }

        return hash_equals($expected, $actual);
    }
}

if (!function_exists('imageExtract')) {
    /**
     * Extract an ISO into a directory.
     *
     * @param string $isoPath   Path to the ISO
     * @param string $targetDir Directory to extract into; created if absent
     * @return array{success: bool, output: string, extractor: string}
     */
    function imageExtract($isoPath, $targetDir) {
        $extractor = imageAvailableExtractor();
        if ($extractor === null) {
            return [
                'success'   => false,
                'extractor' => '',
                'output'    => 'No ISO extraction tool is installed. Install one of: '
                    . implode(', ', array_keys(imageExtractorCandidates())) . '.',
            ];
        }

        if (!is_file($isoPath)) {
            return ['success' => false, 'extractor' => $extractor, 'output' => 'The uploaded image is missing'];
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            return ['success' => false, 'extractor' => $extractor, 'output' => "Could not create $targetDir"];
        }

        $template = imageExtractorCandidates()[$extractor];
        $command = sprintf($template, escapeshellarg($isoPath), escapeshellarg($targetDir)) . ' 2>&1';

        $output = [];
        $code = 1;
        exec($command, $output, $code);

        return [
            'success'   => $code === 0,
            'extractor' => $extractor,
            'output'    => implode("\n", $output),
        ];
    }
}

if (!function_exists('imageLooksBootable')) {
    /**
     * Whether an extracted directory can actually boot a host.
     *
     * boot.cfg has to be there and has to name a kernel and modules. This is
     * the check that turns "installer starts but finds no modules" from a
     * mystery on the console into a rejected upload -- see docs/bootchain.md.
     *
     * ESXi media has boot.cfg at the root and again under efi/boot/; either
     * will do, since the boot endpoints read whichever they find.
     *
     * @param string $dir Extracted image directory
     * @return array{ok: bool, reason: string}
     */
    function imageLooksBootable($dir) {
        require_once __DIR__ . '/bootcfg.php';

        foreach (bootCfgCandidates($dir) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $parsed = parseBootCfg($contents);
            if (bootCfgIsUsable($parsed)) {
                return ['ok' => true, 'reason' => ''];
            }

            return [
                'ok'     => false,
                'reason' => 'boot.cfg is present but names no kernel or no modules',
            ];
        }

        return [
            'ok'     => false,
            'reason' => 'no boot.cfg was found; this does not look like ESXi installer media',
        ];
    }
}

if (!function_exists('imageDirectory')) {
    /**
     * The directory an ESXi version is extracted into.
     *
     * @param string $version Validated version name
     * @return string|null Absolute path, or null when the name is unusable
     */
    function imageDirectory($version) {
        if (!imageIsValidVersionName($version)) {
            return null;
        }

        return AUTODEPLOY_IMAGE_DIR . '/' . $version;
    }
}

if (!function_exists('imageRemoveDirectory')) {
    /**
     * Delete an extracted image directory and everything under it.
     *
     * Refuses anything that is not inside the image directory, so a bad
     * version name cannot turn into a recursive delete somewhere else.
     *
     * @param string $version Version name
     * @return bool True when the directory is gone
     */
    function imageRemoveDirectory($version) {
        $dir = imageDirectory($version);
        if ($dir === null) {
            return false;
        }

        $real = realpath($dir);
        $base = realpath(AUTODEPLOY_IMAGE_DIR);

        if ($real === false) {
            return true; // Nothing there.
        }
        if ($base === false || strncmp($real . '/', $base . '/', strlen($base) + 1) !== 0 || $real === $base) {
            logMessage("Refusing to delete $real: outside the image directory", 'ERROR');
            return false;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($real);
    }
}

if (!function_exists('imageRegister')) {
    /**
     * Record an extracted version in global_config.json.
     *
     * An image on disk that the configuration does not know about cannot be
     * selected for a host, so registration is part of installing it rather
     * than a separate step someone has to remember.
     *
     * @param string $version     Version name
     * @param string $description Human readable description
     * @return bool True on success
     */
    function imageRegister($version, $description = '') {
        $dir = imageDirectory($version);
        if ($dir === null) {
            return false;
        }

        return updateJsonConfig(AUTODEPLOY_GLOBAL_CONFIG, static function (array &$config) use ($version, $dir, $description) {
            if (!isset($config['deployment']) || !is_array($config['deployment'])) {
                $config['deployment'] = [];
            }
            if (!isset($config['deployment']['esxi_versions']) || !is_array($config['deployment']['esxi_versions'])) {
                $config['deployment']['esxi_versions'] = [];
            }

            $config['deployment']['esxi_versions'][$version] = [
                'path'        => $dir,
                'description' => $description !== '' ? $description : $version,
                // Counted once, here, rather than by walking ~500 files on
                // every settings page load and every /api/v1/images call. An
                // extracted medium does not change size on its own; when it
                // does, imageRefreshSize() is the explicit way to say so.
                'size'        => imageDirectorySize($dir),
            ];

            // The first image installed becomes the default, so a fresh
            // appliance can deploy without a second configuration step.
            if (($config['deployment']['default_version'] ?? '') === '') {
                $config['deployment']['default_version'] = $version;
            }

            return true;
        });
    }
}

if (!function_exists('imageUnregister')) {
    /**
     * Remove a version from global_config.json.
     *
     * @param string $version Version name
     * @return bool True on success
     */
    function imageUnregister($version) {
        return updateJsonConfig(AUTODEPLOY_GLOBAL_CONFIG, static function (array &$config) use ($version) {
            unset($config['deployment']['esxi_versions'][$version]);

            // Do not leave the default pointing at something that is gone: a
            // host with no explicit version would fail to boot with a message
            // about a missing image rather than about a missing default.
            if (($config['deployment']['default_version'] ?? '') === $version) {
                $remaining = array_keys($config['deployment']['esxi_versions'] ?? []);
                $config['deployment']['default_version'] = $remaining[0] ?? '';
            }

            return true;
        });
    }
}

if (!function_exists('imageList')) {
    /**
     * Registered versions, with what is actually on disk.
     *
     * Reports both so the two can be seen to disagree -- a registered version
     * whose directory was removed by hand is exactly the configuration that
     * produces a host stuck at the boot prompt.
     *
     * @return array<int, array{version: string, description: string, path: string, present: bool, bootable: bool, size: int}>
     */
    function imageList() {
        $config = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG) ?? [];
        $versions = $config['deployment']['esxi_versions'] ?? [];

        $images = [];
        foreach ($versions as $version => $meta) {
            $dir = is_array($meta) ? ($meta['path'] ?? '') : '';
            if ($dir === '') {
                $dir = (string)imageDirectory((string)$version);
            }

            $present = is_dir($dir);

            // present and bootable are two stats and one small file read, so
            // they are checked live -- a version whose directory was removed
            // by hand has to show as gone. The size is not: walking every file
            // of every installed version, on every render, is the one part of
            // this that grows with the number of images. It is recorded at
            // installation and refreshed on request.
            $images[] = [
                'version'     => (string)$version,
                'description' => is_array($meta) ? (string)($meta['description'] ?? '') : '',
                'path'        => $dir,
                'present'     => $present,
                'bootable'    => $present && imageLooksBootable($dir)['ok'],
                'size'        => $present ? (int)(is_array($meta) ? ($meta['size'] ?? 0) : 0) : 0,
            ];
        }

        return $images;
    }
}

if (!function_exists('imageRefreshSize')) {
    /**
     * Recount an installed image and record the result.
     *
     * The counterpart to the size imageRegister() stores: an operator who has
     * changed what is on disk underneath the appliance needs a way to say so,
     * and imageList() no longer finds out by itself.
     *
     * @param string $version Version name
     * @return int The size in bytes, or 0 when the image is not on disk
     */
    function imageRefreshSize($version) {
        $dir = imageDirectory($version);
        if ($dir === null || !is_dir($dir)) {
            return 0;
        }

        $size = imageDirectorySize($dir);

        updateJsonConfig(AUTODEPLOY_GLOBAL_CONFIG, static function (array &$config) use ($version, $size) {
            if (!isset($config['deployment']['esxi_versions'][$version])
                || !is_array($config['deployment']['esxi_versions'][$version])) {
                return false;
            }

            $config['deployment']['esxi_versions'][$version]['size'] = $size;

            return true;
        });

        return $size;
    }
}

if (!function_exists('imageDirectorySize')) {
    /**
     * Total size of an extracted image, in bytes.
     *
     * Walks the whole tree -- ~500 files for an ESXi medium -- so it is called
     * at installation and on an explicit refresh, not on render.
     *
     * @param string $dir Directory
     * @return int
     */
    function imageDirectorySize($dir) {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile()) {
                $total += $item->getSize();
            }
        }

        return $total;
    }
}

if (!function_exists('imageInstall')) {
    /**
     * Install an uploaded ISO as a bootable ESXi version.
     *
     * Verify, extract, check, register -- and undo the whole thing if any step
     * fails, so a failed upload never leaves a half-extracted directory that
     * a host might try to boot from.
     *
     * @param string $isoPath      Path to the uploaded ISO
     * @param string $version      Version name to install as
     * @param string $description  Human readable description
     * @param string $expectedHash Optional SHA-256 to verify against
     * @return array{success: bool, error: string, message: string, extractor: string}
     */
    function imageInstall($isoPath, $version, $description = '', $expectedHash = '') {
        $result = ['success' => false, 'error' => '', 'message' => '', 'extractor' => ''];

        if (!imageIsValidVersionName($version)) {
            $result['error'] = 'The version name may only contain letters, digits, dot, dash and underscore';
            return $result;
        }

        $dir = (string)imageDirectory($version);

        if (is_dir($dir)) {
            $result['error'] = "ESXi version '$version' is already installed; delete it first";
            return $result;
        }

        if ($expectedHash !== '') {
            if (!imageVerifyHash($isoPath, $expectedHash)) {
                $result['error'] = 'The uploaded image does not match the SHA-256 you supplied';
                return $result;
            }
            logMessage("Image for $version matched its SHA-256");
        } else {
            logMessage("Image for $version uploaded without a hash to verify against", 'WARNING');
        }

        $extraction = imageExtract($isoPath, $dir);
        $result['extractor'] = $extraction['extractor'];

        if (!$extraction['success']) {
            imageRemoveDirectory($version);
            $result['error'] = 'Extraction failed: ' . $extraction['output'];
            return $result;
        }

        $bootable = imageLooksBootable($dir);
        if (!$bootable['ok']) {
            // Better to refuse it now than to have a host discover it at the
            // boot prompt with nobody watching.
            imageRemoveDirectory($version);
            $result['error'] = 'The extracted image is not usable: ' . $bootable['reason'];
            return $result;
        }

        if (!imageRegister($version, $description)) {
            imageRemoveDirectory($version);
            $result['error'] = 'The image extracted but could not be registered in the configuration';
            return $result;
        }

        logMessage("Installed ESXi version $version using {$extraction['extractor']}");

        $result['success'] = true;
        $result['message'] = "ESXi version '$version' installed and ready to deploy";

        return $result;
    }
}
