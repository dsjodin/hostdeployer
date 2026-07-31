<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Template file names and the paths they turn into.
 *
 * A template name arrives from POST and becomes a filesystem path. Without the
 * validation below, a logged-in operator could read, overwrite or delete
 * anything the web server can reach -- config/credentials.json, or a new .php
 * file inside the document root, which is remote code execution.
 *
 * It had no tests, because it lived two hundred lines into a 1772-line file
 * that also contained CSS. It is in lib/ now, which is what makes this
 * possible.
 */
final class TemplateFilesTest extends TestCase
{
    private string $templatesDir = '';

    protected function setUp(): void
    {
        $this->templatesDir = AUTODEPLOY_ROOT . '/templates';
        @mkdir($this->templatesDir . '/backups', 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templatesDir . '/backups/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->templatesDir . '/*.cfg') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->templatesDir . '/backups');
    }

    // -----------------------------------------------------------------------
    // Names
    // -----------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function acceptedNames(): array
    {
        return [
            'shipped standard' => ['kickstart_template_std.cfg'],
            'shipped vcf'      => ['kickstart_template_vcf.cfg'],
            'dashed'           => ['my-template.cfg'],
            'dotted'           => ['site.prod.cfg'],
            'digits'           => ['8021q.cfg'],
        ];
    }

    #[DataProvider('acceptedNames')]
    public function testAPlainTemplateNameIsAccepted(string $name): void
    {
        self::assertTrue(isValidTemplateName($name));
    }

    /** @return array<string, array{mixed}> */
    public static function refusedNames(): array
    {
        return [
            'parent traversal'   => ['../credentials.json'],
            'deep traversal'     => ['../../etc/passwd'],
            'absolute'           => ['/etc/passwd'],
            'subdirectory'       => ['sub/template.cfg'],
            'backslash'          => ['..\\template.cfg'],
            'php extension'      => ['shell.php'],
            'double extension'   => ['shell.cfg.php'],
            'no extension'       => ['template'],
            'wrong extension'    => ['template.txt'],
            'dotfile'            => ['.cfg'],
            'leading dot'        => ['.hidden.cfg'],
            'leading dash'       => ['-rf.cfg'],
            'null byte'          => ["good.cfg\0.php"],
            'newline'            => ["good.cfg\n"],
            'space'              => ['my template.cfg'],
            'empty'              => [''],
            'just dots'          => ['...cfg'],
            'too long'           => [str_repeat('a', 130) . '.cfg'],
        ];
    }

    /** @param mixed $name */
    #[DataProvider('refusedNames')]
    public function testAnUnsafeNameIsRefused($name): void
    {
        self::assertFalse(isValidTemplateName($name));
        self::assertNull(resolveTemplatePath($name, $this->templatesDir));
    }

    public function testAResolvedPathStaysInTheTemplatesDirectory(): void
    {
        self::assertSame(
            $this->templatesDir . '/site.cfg',
            resolveTemplatePath('site.cfg', $this->templatesDir)
        );
    }

    // -----------------------------------------------------------------------
    // Backups
    // -----------------------------------------------------------------------

    public function testABackupNameIsATemplateNameWithATimestamp(): void
    {
        self::assertTrue(isValidBackupName('site.cfg.20260731_120000'));
        self::assertSame('site.cfg', templateNameFromBackup('site.cfg.20260731_120000'));
        self::assertSame(
            $this->templatesDir . '/backups/site.cfg.20260731_120000',
            resolveBackupPath('site.cfg.20260731_120000', $this->templatesDir)
        );
    }

    /** @return array<string, array{string}> */
    public static function refusedBackupNames(): array
    {
        return [
            'traversal'            => ['../../etc/passwd.cfg.20260731_120000'],
            'no timestamp'         => ['site.cfg'],
            'short timestamp'      => ['site.cfg.2026_12'],
            'unsafe base name'     => ['shell.php.20260731_120000'],
            'timestamp only'       => ['20260731_120000'],
            'trailing php'         => ['site.cfg.20260731_120000.php'],
        ];
    }

    #[DataProvider('refusedBackupNames')]
    public function testAnUnsafeBackupNameIsRefused(string $name): void
    {
        self::assertFalse(isValidBackupName($name));
        self::assertNull(resolveBackupPath($name, $this->templatesDir));
        self::assertNull(templateNameFromBackup($name));
    }

    // -----------------------------------------------------------------------
    // Saving, backing up and restoring
    // -----------------------------------------------------------------------

    public function testSavingWritesTheContent(): void
    {
        $path = $this->templatesDir . '/site.cfg';

        self::assertTrue(saveTemplateFile($path, "first\n", false));
        self::assertSame("first\n", file_get_contents($path));
    }

    public function testSavingBacksUpWhatWasThere(): void
    {
        $path = $this->templatesDir . '/site.cfg';
        file_put_contents($path, "original\n");

        self::assertTrue(saveTemplateFile($path, "replacement\n", true));

        $backups = getTemplateBackups($path);
        self::assertCount(1, $backups);
        self::assertSame("original\n", file_get_contents($backups[0]['path']));
        self::assertSame("replacement\n", file_get_contents($path));
    }

    public function testRestoringPutsTheBackupBack(): void
    {
        $path = $this->templatesDir . '/site.cfg';
        file_put_contents($path, "original\n");
        saveTemplateFile($path, "broken\n", true);

        $backups = getTemplateBackups($path);
        self::assertTrue(restoreTemplateFromBackup($backups[0]['path'], $path));

        self::assertSame("original\n", file_get_contents($path));
    }

    /**
     * Restore backs the current file up before overwriting it. Backup names
     * have one-second granularity, so in the same second that backup wanted
     * the name of the backup being restored from -- and overwriting it meant
     * copying the broken content straight back over the good.
     */
    public function testRestoringInTheSameSecondDoesNotOverwriteItsOwnSource(): void
    {
        $path = $this->templatesDir . '/site.cfg';
        file_put_contents($path, "original\n");
        saveTemplateFile($path, "broken\n", true);

        $source = getTemplateBackups($path)[0]['path'];
        self::assertSame("original\n", file_get_contents($source));

        restoreTemplateFromBackup($source, $path);

        self::assertSame("original\n", file_get_contents($source), 'the backup was overwritten');
        self::assertSame("original\n", file_get_contents($path));
    }

    public function testASecondSaveInTheSameSecondKeepsTheOlderBackup(): void
    {
        $path = $this->templatesDir . '/site.cfg';
        file_put_contents($path, "first\n");

        saveTemplateFile($path, "second\n", true);
        saveTemplateFile($path, "third\n", true);

        // The oldest content is the one worth being able to go back to.
        $backups = getTemplateBackups($path);
        self::assertSame("first\n", file_get_contents($backups[0]['path']));
    }

    public function testTheListingReportsWhatIsThere(): void
    {
        file_put_contents($this->templatesDir . '/kickstart_template_std.cfg', "std\n");
        file_put_contents($this->templatesDir . '/kickstart_template_vcf.cfg', "vcf\n");

        $files = getTemplateFiles($this->templatesDir);

        self::assertCount(2, $files);

        $byName = [];
        foreach ($files as $file) {
            $byName[$file['filename']] = $file;
        }

        self::assertArrayHasKey('kickstart_template_std.cfg', $byName);
        self::assertSame('Standard ESXi', $byName['kickstart_template_std.cfg']['type']);
        self::assertSame('VMware Cloud Foundation', $byName['kickstart_template_vcf.cfg']['type']);
        self::assertNotSame('', $byName['kickstart_template_std.cfg']['size_formatted']);
    }

    public function testListingAMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], getTemplateFiles(AUTODEPLOY_ROOT . '/no-such-directory'));
    }
}
