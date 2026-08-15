<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Helpers\ConfigMerge;
use zFramework\Kernel\Terminal;
use ZipArchive;

/**
 * Update the framework core from GitHub.
 *
 * The whole difficulty is deciding what "the framework" is. The repository zip
 * carries a complete project - App/, config/, route/, resource/, public_html/ -
 * and all of that is the application, not the framework. Overwriting it would
 * destroy the thing being updated.
 *
 * So only these are replaced:
 *
 *   zFramework/bootstrap.php  zFramework/run.php
 *   zFramework/Core/  zFramework/Kernel/  zFramework/modules/
 *
 * and these are never touched, because neither is in the repository and both
 * would be lost: zFramework/vendor/ (composer's) and zFramework/storage/
 * (sessions, caches, logs, locks).
 *
 * config/ is neither replaced nor ignored - see ConfigMerge.
 */
class Update
{
    private const REPO   = 'mustafaomereser/zFramework';
    private const BRANCH = 'main';

    /**
     * Replaced wholesale. Everything else under zFramework/ is left alone.
     */
    private const CORE = ['bootstrap.php', 'run.php', 'Core', 'Kernel', 'modules'];

    public static function begin($methods)
    {
        if (in_array('--rollback', Terminal::$parameters)) return self::rollback();
        if (in_array('--check', Terminal::$parameters))    return self::check();

        self::run();
    }

    /**
     * Description: Update the framework core from GitHub
     * Usage: php terminal update [--check] [--config] [--force] [--rollback]
     * @param --check    (optional) only report whether a newer version exists
     * @param --config   (optional) write the merged config files, not just report
     * @param --force    (optional) update even when the versions match
     * @param --rollback (optional) restore the most recent backup
     */
    public static function run()
    {
        $remote = self::remoteVersion();
        if ($remote === null) return;

        Terminal::text('[color=dark-gray]installed ' . FRAMEWORK_VERSION . ', remote ' . $remote . '[/color]');

        # version_compare, not equality: running a build ahead of the branch is
        # normal while developing, and "3.0.0 available" would be nonsense there.
        if (version_compare($remote, FRAMEWORK_VERSION, '<=') && !in_array('--force', Terminal::$parameters))
            return Terminal::text('[color=green]Already up to date.[/color]');

        # Everything this command still needs has to be in memory before the
        # files go. The replace step deletes Kernel/, and a class autoloaded
        # after that is looked for in a directory that no longer holds it -
        # which is how the first version of this died half way through, with
        # ConfigMerge gone and the core already swapped.
        class_exists(ConfigMerge::class);

        global $storage_path;
        $work = "$storage_path/update";

        rrmdir($work);
        if (!@mkdir($work, 0755, true)) return Terminal::text('[color=red]Cannot write to storage/update.[/color]');

        # 1. download
        Terminal::text('[color=yellow]downloading...[/color]');
        $zip = "$work/source.zip";
        if (!self::download('https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip', $zip)) {
            return Terminal::text('[color=red]Download failed.[/color]');
        }

        # A failed request often arrives as an HTML error page. Checking the
        # signature is cheaper than letting ZipArchive fail obscurely later.
        if (@file_get_contents($zip, false, null, 0, 2) !== 'PK')
            return Terminal::text('[color=red]What was downloaded is not a zip.[/color]');

        # 2. extract
        Terminal::text('[color=yellow]extracting...[/color]');
        $archive = new ZipArchive;
        if ($archive->open($zip) !== true) return Terminal::text('[color=red]Cannot open the archive.[/color]');
        $root = rtrim((string) $archive->getNameIndex(0), '/');
        $archive->extractTo($work);
        $archive->close();

        $source = "$work/$root/zFramework";

        # 3. sanity: refuse to touch anything unless this really is the framework
        foreach (['bootstrap.php', 'run.php', 'Core'] as $must) {
            if (file_exists("$source/$must")) continue;
            return Terminal::text("[color=red]The archive does not look like zFramework ($must missing) - nothing was changed.[/color]");
        }

        # 4. back up what is about to be replaced
        $backup = "$storage_path/update-backup/" . FRAMEWORK_VERSION . '-' . date('Ymd-His');
        Terminal::text('[color=yellow]backing up to ' . str_replace(BASE_PATH, '', $backup) . '[/color]');

        foreach (self::CORE as $item) {
            $from = FRAMEWORK_PATH . "/$item";
            if (file_exists($from) && !self::copy($from, "$backup/$item"))
                return Terminal::text("[color=red]Backup of $item failed - nothing was changed.[/color]");
        }

        # 5. replace, core only
        Terminal::text('[color=yellow]replacing the core...[/color]');
        foreach (self::CORE as $item) {
            $target = FRAMEWORK_PATH . "/$item";
            if (is_dir($target)) rrmdir($target);
            elseif (is_file($target)) @unlink($target);

            self::copy("$source/$item", $target);
        }

        # 6. config
        self::configs("$work/$root/config");

        # 7. anything that needs a human
        if (@file_get_contents("$work/$root/composer.json") !== @file_get_contents(BASE_PATH . '/composer.json'))
            Terminal::text('[color=yellow]composer.json changed - run `composer install`.[/color]');

        rrmdir($work . "/$root");
        @unlink($zip);

        # 8. compiled state was built against the old core
        rrmdir("$storage_path/views");
        @unlink("$storage_path/routes.cache.php");
        @file_put_contents("$storage_path/framework-version", $remote);

        Terminal::text('[color=green]Updated to ' . $remote . '.[/color]');
        Terminal::text('[color=dark-gray]`php terminal update --rollback` restores the backup.[/color]');
    }

    /**
     * Description: Report whether a newer version exists
     * Usage: php terminal update --check
     */
    public static function check()
    {
        $remote = self::remoteVersion();
        if ($remote === null) return;

        Terminal::text(version_compare($remote, FRAMEWORK_VERSION, '<=')
            ? '[color=green]Up to date (' . FRAMEWORK_VERSION . ').[/color]'
            : '[color=yellow]' . FRAMEWORK_VERSION . ' installed, ' . $remote . ' available.[/color]');
    }

    /**
     * Description: Restore the most recent backup
     * Usage: php terminal update --rollback
     */
    public static function rollback()
    {
        global $storage_path;

        $backups = (array) glob("$storage_path/update-backup/*", GLOB_ONLYDIR);
        if (!$backups) return Terminal::text('[color=red]No backup to restore.[/color]');

        rsort($backups);
        $backup = $backups[0];

        foreach (self::CORE as $item) {
            if (!file_exists("$backup/$item")) continue;

            $target = FRAMEWORK_PATH . "/$item";
            if (is_dir($target)) rrmdir($target);
            elseif (is_file($target)) @unlink($target);

            self::copy("$backup/$item", $target);
        }

        rrmdir("$storage_path/views");
        @unlink("$storage_path/routes.cache.php");

        Terminal::text('[color=green]Restored ' . basename($backup) . '.[/color]');
    }

    /**
     * Merge each shipped config file into the application's.
     *
     * Reports by default. Nothing about a config file is safe to assume, and a
     * merge you did not ask for is how settings quietly change.
     *
     * @param string $shippedDir
     * @return void
     */
    private static function configs(string $shippedDir): void
    {
        $apply = in_array('--config', Terminal::$parameters);
        $any   = false;

        foreach ((array) glob("$shippedDir/*.php") as $file) {
            $name    = basename($file);
            $current = BASE_PATH . "/config/$name";

            if (!is_file($current)) {
                Terminal::text("[color=yellow]config/{$name} is new in this version - not added, copy it if you want it.[/color]");
                continue;
            }

            $shipped = (string) file_get_contents($file);
            $mine    = (string) file_get_contents($current);

            $merged = ConfigMerge::merge($shipped, $mine);
            $drift  = ConfigMerge::keyDrift($shipped, $mine);

            if (!$merged['changes'] && !$merged['manual'] && !$drift['added'] && !$drift['removed']) continue;

            $any = true;
            Terminal::text("[color=yellow]config/{$name}[/color]");

            foreach ($drift['added'] as $key)   Terminal::text("  [color=green]+ {$key}[/color] [color=dark-gray]new in this version[/color]");
            foreach ($drift['removed'] as $key) Terminal::text("  [color=dark-gray]- {$key} no longer shipped[/color]");
            foreach ($merged['changes'] as $c)  Terminal::text("  [color=dark-gray]{$c}[/color]");
            foreach ($merged['manual'] as $key) Terminal::text("  [color=red]! {$key} must be added back by hand[/color]");

            if (!$apply) continue;

            # The pre-merge file is already in the backup taken above, but this
            # one sits next to the file so it is obvious what to compare against.
            @copy($current, "$current.before-update");
            @file_put_contents($current, $merged['source']);
            Terminal::text("  [color=green]written; the previous file is {$name}.before-update[/color]");
        }

        if ($any && !$apply) Terminal::text('[color=dark-gray]Nothing was written. Re-run with --config to apply.[/color]');
    }

    /**
     * The version the remote branch declares, read from one file rather than by
     * downloading the whole archive to find out.
     *
     * @return string|null
     */
    private static function remoteVersion(): ?string
    {
        $url  = 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/zFramework/bootstrap.php';
        $body = self::fetch($url);

        if ($body === null) {
            Terminal::text('[color=red]Cannot reach GitHub.[/color]');
            return null;
        }

        if (!preg_match("/FRAMEWORK_VERSION'\s*,\s*'([^']+)'/", $body, $m)) {
            Terminal::text('[color=red]No version found in the remote bootstrap.php.[/color]');
            return null;
        }

        return $m[1];
    }

    /**
     * @param string $url
     * @return string|null
     */
    private static function fetch(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'zFramework-updater',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($body !== false && $code === 200) ? (string) $body : null;
        }

        # allow_url_fopen is off on plenty of shared hosts, which is why curl is
        # tried first rather than this.
        $body = @file_get_contents($url);

        return $body === false ? null : $body;
    }

    /**
     * @param string $url
     * @param string $to
     * @return bool
     */
    private static function download(string $url, string $to): bool
    {
        $body = self::fetch($url);

        return $body !== null && @file_put_contents($to, $body) !== false;
    }

    /**
     * Recursive copy, file or directory.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    private static function copy(string $from, string $to): bool
    {
        if (is_file($from)) {
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) return false;
            return (bool) @copy($from, $to);
        }

        if (!is_dir($from)) return false;
        if (!is_dir($to) && !@mkdir($to, 0755, true)) return false;

        foreach (scan_dir($from) as $entry)
            if (!self::copy("$from/$entry", "$to/$entry")) return false;

        return true;
    }
}
