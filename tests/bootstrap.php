<?php
/**
 * PHPUnit bootstrap.
 *
 * lib/utils.php defines AUTODEPLOY_ROOT and the paths derived from it at
 * include time, so the environment variable has to be set before anything
 * requires it. Pointing it at a temporary directory keeps the tests from
 * reading /srv/autodeploy on a machine that happens to have a real install.
 */

declare(strict_types=1);

$root = sys_get_temp_dir() . '/hostdeployer-tests-' . getmypid();

foreach (['config', 'logs', 'templates', 'esxi', 'sessions'] as $dir) {
    if (!is_dir("$root/$dir") && !mkdir("$root/$dir", 0o750, true) && !is_dir("$root/$dir")) {
        fwrite(STDERR, "could not create test fixture directory $root/$dir\n");
        exit(1);
    }
}

putenv("AUTODEPLOY_ROOT=$root");

// CsrfTest exercises the real session helpers, which means a real session.
// Keep its files inside the fixture tree: the CLI default is
// /var/lib/php/sessions, which is root-owned on Debian and absent in some CI
// images, and a test that needs a writable system directory is a test that
// fails for reasons having nothing to do with the code.
ini_set('session.save_path', "$root/sessions");

// PHP flushes session data during request shutdown, after the shutdown
// functions have run -- by which point the cleanup below has removed the
// directory it would write to. Closing the session first is registered first,
// because shutdown functions run in registration order.
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
});

// Remove the fixture tree when the run ends, however it ends.
register_shutdown_function(static function () use ($root): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($root);
});

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/bootcfg.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/secrets.php';
require_once __DIR__ . '/../lib/store.php';

// lib/auth.php no longer starts a session when it is included, which is what
// makes it safe to pull in here. api_auth.php builds on it.
require_once __DIR__ . '/../lib/images.php';
require_once __DIR__ . '/../lib/kea.php';
require_once __DIR__ . '/../lib/api_auth.php';
