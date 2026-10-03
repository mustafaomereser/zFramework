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
    private const REPO = 'mustafaomereser/zFramework';

    /**
     * Where development happens. Every release is also a branch, `vX.Y.Z-release`,
     * so a version can be installed by name and main can be installed as it stands.
     */
    private const MAIN = 'main';

    /**
     * Replaced wholesale. Everything else under zFramework/ is left alone.
     */
    private const CORE = ['bootstrap.php', 'run.php', 'Core', 'Kernel', 'modules'];

    /**
     * Entry points that live in the application but are written by the framework.
     * Never replaced - an index.php is often customised - only compared.
     * Published path => where it lives here.
     */
    private const ENTRY_POINTS = [
        'terminal'              => '/terminal',
        'cron/cron.php'         => '/cron/cron.php',
        'public_html/index.php' => null, // public_dir(), whatever the directory is called
    ];

    /**
     * Language keys the core itself reads, per file: null for the whole file, or
     * the subtree. The rest of resource/lang is the application's own text.
     */
    /**
     * Keys that differ per machine. Moved between config files they are written
     * as null, never with this machine's value - see configs().
     */
    private const ENVIRONMENT_KEYS = ['debug', 'force-https'];

    private const CORE_LANG = [
        'errors'    => null,
        'validator' => 'errors',
    ];

    public static function begin($methods)
    {
        if (in_array('--rollback', Terminal::$parameters)) return self::rollback();
        if (in_array('--check', Terminal::$parameters))    return self::check();

        # Internal: run() calls this in a fresh process once the new core is in place,
        # so the report comes from the version just installed - see report().
        if (isset(Terminal::$parameters['--report'])) return self::report((string) Terminal::$parameters['--report']);

        # `php terminal help` lists check and rollback under this command, because both
        # are public and documented - so they get typed as subcommands, and without
        # this they fell through to run(), which replaces the core instead of reporting
        # on it or putting it back. An unrecognised word is refused for the same reason:
        # nothing here should update by accident.
        $sub = strtolower((string) (Terminal::$commands[1] ?? ''));

        if ($sub !== '') {
            if (!in_array($sub, $methods, true))
                return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');

            return self::{$sub}();
        }

        self::run();
    }

    /**
     * Description: Update the framework core from GitHub - a release branch or main
     * Usage: php terminal update [--branch=v3.2.0-release|main] [--check] [--config] [--force] [--rollback]
     * @param --branch   (optional) which branch to install; without it the branches are listed and asked
     * @param --check    (optional) only list the branches and whether a newer version exists
     * @param --config   (optional) write the merged config files, not just report
     * @param --force    (optional) install even when it is the same version, or an older one
     * @param --rollback (optional) restore the most recent backup
     */
    public static function run()
    {
        $branch = self::branch();
        if ($branch === null) return;

        $remote = self::remoteVersion($branch);
        if ($remote === null) return;

        Terminal::text('[color=dark-gray]installed ' . FRAMEWORK_VERSION . ', ' . $branch . ' is ' . $remote . '[/color]');

        # Same version, or an older one, needs saying twice. Running a build ahead
        # of the branch is normal while developing, so "already up to date" is the
        # quiet answer; going backwards is allowed - that is what release branches
        # are for - but only on purpose.
        if (!in_array('--force', Terminal::$parameters)) {
            if (version_compare($remote, FRAMEWORK_VERSION, '=='))
                return Terminal::text('[color=green]Already ' . $remote . '. Add --force to reinstall it.[/color]');

            if (version_compare($remote, FRAMEWORK_VERSION, '<'))
                return Terminal::text('[color=yellow]' . $branch . ' is ' . $remote . ', older than the installed ' . FRAMEWORK_VERSION . '. Add --force to downgrade.[/color]');
        }

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
        if (!self::download('https://github.com/' . self::REPO . '/archive/refs/heads/' . $branch . '.zip', $zip)) {
            return Terminal::text('[color=red]Download failed - ' . (self::$fetchError ?? 'could not write storage/update/source.zip') . '.[/color]');
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

        # 5. replace, core only. Each copy is checked: the target was just
        # deleted, so a copy that fails half-way - permissions, a full disk -
        # leaves a partial core. On failure the backup taken above goes straight
        # back and the command says so instead of printing "Updated".
        Terminal::text('[color=yellow]replacing the core...[/color]');
        foreach (self::CORE as $item) {
            $target = FRAMEWORK_PATH . "/$item";
            if (!file_exists("$source/$item")) continue;
            if (is_dir($target)) rrmdir($target);
            elseif (is_file($target)) @unlink($target);

            if (!self::copy("$source/$item", $target)) {
                Terminal::text("[color=red]Copying $item failed - restoring the backup.[/color]");
                foreach (self::CORE as $restore) {
                    if (!file_exists("$backup/$restore")) continue;
                    $t = FRAMEWORK_PATH . "/$restore";
                    if (is_dir($t)) rrmdir($t);
                    elseif (is_file($t)) @unlink($t);
                    self::copy("$backup/$restore", $t);
                }
                rrmdir($work . "/$root");
                @unlink($zip);
                return Terminal::text('[color=red]Update aborted; the previous core is back in place.[/color]');
            }
        }

        # 6-7. config, the application's own files, composer - reported by the core
        # just installed, not by this one. This class was loaded from the old core, so
        # whatever the new version learned to report (the entry points, the language
        # keys) stayed silent until `update` was run a second time. A fresh process
        # loads the new Update.php. Should it fail - or under --json, whose output
        # would not reach the caller - this old code reports instead.
        $reported = false;
        if (!in_array('--json', Terminal::$parameters) && function_exists('passthru')) {
            $flags = array_values(array_intersect(['--config', '--web'], Terminal::$parameters));
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/terminal') . ' update --report=' . escapeshellarg("$work/$root") . ($flags ? ' ' . implode(' ', $flags) : ''), $code);
            $reported = $code === 0;
        }
        if (!$reported) self::report("$work/$root");

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
     * Description: List the branches that can be installed, and whether a newer version exists
     * Usage: php terminal update --check
     */
    public static function check()
    {
        $branches = self::branches();
        if ($branches === null) return;

        $main = self::remoteVersion(self::MAIN);
        if ($main === null) return;

        self::listBranches($branches, $main);

        $newest = $main;
        foreach ($branches as $b) if ($b['version'] && version_compare($b['version'], $newest, '>')) $newest = $b['version'];

        Terminal::text(version_compare($newest, FRAMEWORK_VERSION, '<=')
            ? '[color=green]Up to date (' . FRAMEWORK_VERSION . ').[/color]'
            : '[color=yellow]' . FRAMEWORK_VERSION . ' installed, ' . $newest . ' available - `php terminal update` to choose.[/color]');
    }

    /**
     * Which branch to install: --branch if given, otherwise asked for.
     *
     * @return string|null
     */
    private static function branch(): ?string
    {
        $branches = self::branches();
        if ($branches === null) return null;

        if ($asked = Terminal::$parameters['--branch'] ?? null) {
            $asked = (string) $asked;
            # A bare version is accepted as its release branch: --branch=3.0.0.
            if (preg_match('/^\d+\.\d+\.\d+$/', $asked)) $asked = "v$asked-release";
            foreach ($branches as $b) if ($b['name'] === $asked) return $asked;

            Terminal::text('[color=red]No branch called `' . $asked . '`.[/color]');
            self::listBranches($branches, null);
            return null;
        }

        # Nothing to ask from the welcome page's terminal, or with no readline.
        if (in_array('--web', Terminal::$parameters) || !function_exists('readline')) {
            self::listBranches($branches, null);
            Terminal::text('[color=yellow]Say which: `php terminal update --branch=<name>`.[/color]');
            return null;
        }

        self::listBranches($branches, null);
        Terminal::text("\n[color=yellow]*[/color] [color=blue]Which one? (number, empty to stop)[/color]");
        $pick = trim((string) readline('> '));
        if ($pick === '') {
            Terminal::text('[color=blue]Nothing installed.[/color]');
            return null;
        }

        $chosen = $branches[(int) $pick - 1] ?? null;
        if (!$chosen) {
            Terminal::text('[color=red]Selection is not acceptable.[/color]');
            return null;
        }

        return $chosen['name'];
    }

    /**
     * The installable branches: main first, then every release, newest first.
     *
     * @return array<int, array{name: string, version: ?string}>|null Null when GitHub cannot be reached.
     */
    private static function branches(): ?array
    {
        $body = self::fetch('https://api.github.com/repos/' . self::REPO . '/branches?per_page=100');
        $list = $body !== null ? json_decode($body, true) : null;

        if (!is_array($list)) {
            Terminal::text('[color=red]Cannot list the branches - ' . (self::$fetchError ?? 'GitHub sent something that is not a branch list') . '.[/color]');
            return null;
        }

        $releases = [];
        foreach ($list as $b) if (preg_match('/^v(\d+\.\d+\.\d+)-release$/', (string) ($b['name'] ?? ''), $m)) $releases[] = ['name' => $b['name'], 'version' => $m[1]];

        usort($releases, fn($a, $b) => version_compare($b['version'], $a['version']));

        return array_merge([['name' => self::MAIN, 'version' => null]], $releases);
    }

    /**
     * @param array       $branches
     * @param string|null $mainVersion What main's bootstrap.php says, when it has been read.
     * @return void
     */
    private static function listBranches(array $branches, ?string $mainVersion): void
    {
        Terminal::text('[color=dark-gray]installed: ' . FRAMEWORK_VERSION . '[/color]');

        foreach ($branches as $i => $b) {
            $version = $b['version'] ?? $mainVersion;
            $note    = $b['name'] === self::MAIN ? 'development - what is being worked on now' : '';
            $mark    = $version !== null && version_compare($version, FRAMEWORK_VERSION, '==') ? ' [color=green](installed)[/color]' : '';

            Terminal::text(sprintf('  [color=yellow]%2d[/color]  %-20s [color=dark-gray]%s[/color]%s', $i + 1, $b['name'], $version ? $version . ($note ? " - $note" : '') : $note, $mark));
        }
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

        # By the date suffix, not the name: the name starts with the version and
        # rsort() on strings put 3.9.0 above 3.10.0, restoring the wrong core.
        usort($backups, fn($a, $b) => strcmp(substr($b, -15), substr($a, -15)));
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
     * Everything after the core swap that needs a human: config drift, the
     * application's own files, composer.
     *
     * @param string $shipped The extracted release root.
     * @return void
     */
    private static function report(string $shipped): void
    {
        if (!is_dir($shipped)) {
            Terminal::text('[color=red]Nothing to report on: ' . $shipped . ' is not there.[/color]');
            return;
        }

        self::configs("$shipped/config");
        self::projectFiles($shipped);
        self::composer($shipped);
    }

    /**
     * The packages the core needs, against the application's composer.json.
     *
     * Only `require`, and only the shipped side's packages: the file as a whole
     * differs in every real project - its own packages, a name, scripts - and
     * comparing all of it said "composer.json changed" after every update.
     *
     * @param string $shipped
     * @return void
     */
    private static function composer(string $shipped): void
    {
        $need = json_decode((string) @file_get_contents("$shipped/composer.json"), true)['require'] ?? null;
        $have = json_decode((string) @file_get_contents(BASE_PATH . '/composer.json'), true)['require'] ?? [];
        if (!is_array($need)) return;

        $differ = [];
        foreach ($need as $package => $constraint)
            if (($have[$package] ?? null) !== $constraint) $differ[] = "$package $constraint" . (isset($have[$package]) ? " (yours: {$have[$package]})" : ' (missing)');

        if ($differ) Terminal::text('[color=yellow]composer.json: this version requires ' . implode(', ', $differ) . ' - update `require` and run `composer install`.[/color]');
    }

    /**
     * What the update could not do for the application, said out loud.
     *
     * Only the core is replaced, so an entry point whose new version boots
     * differently (cron.php gained the error handler and $cron_mode) stayed old
     * in every project that predates it, and a language file stayed without the
     * messages of rules added since - an upgraded 2.x project answered seven
     * 3.x rules with nothing. Neither is safe to overwrite; both are reported,
     * and a changed entry point is left next to the backup to diff against.
     *
     * @param string $shipped The extracted release root.
     * @return void
     */
    private static function projectFiles(string $shipped): void
    {
        global $storage_path;
        $normalise = fn(string $file) => str_replace("\r\n", "\n", (string) @file_get_contents($file));
        $keep      = "$storage_path/update-shipped";

        # Only this update's: a copy left from an earlier one would be compared with
        # files it no longer describes.
        rrmdir($keep);

        foreach (self::ENTRY_POINTS as $published => $local) {
            $new  = "$shipped/$published";
            $mine = $local === null ? public_dir('/index.php') : BASE_PATH . $local;
            if (!is_file($new) || (is_file($mine) && $normalise($mine) === $normalise($new))) continue;

            @mkdir(dirname("$keep/$published"), 0755, true);
            @copy($new, "$keep/$published");
            $shown = str_replace(path_fix(BASE_PATH), '', path_fix("$keep/$published"));
            Terminal::text("[color=yellow]$published differs from this release's - compare with $shown (kept, not applied).[/color]");
        }

        # Language: every locale the application has, against what the core reads -
        # zFramework/Core/lang, already the new version's by now. Not the skeleton's
        # resource/lang: that is an example application, and a key missing from it
        # (validator.errors.nullable was) went unreported in every project too.
        foreach (self::CORE_LANG as $file => $subtree) {
            $core = FRAMEWORK_PATH . "/Core/lang/$file.php";
            if (!is_file($core)) continue;

            $read = function (string $path) use ($subtree): array {
                $data = is_file($path) ? (static fn() => include $path)() : [];
                $data = is_array($data) ? $data : [];
                return $subtree === null ? $data : (is_array($data[$subtree] ?? null) ? $data[$subtree] : []);
            };
            $wanted = self::keyPaths($read($core));

            foreach (glob(BASE_PATH . '/resource/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $locale  = basename($dir);
                $mine    = "$dir/$file.php";
                $missing = array_diff($wanted, self::keyPaths($read($mine)));
                if (!$missing) continue;

                $prefix = $file . ($subtree ? ".$subtree" : '');
                Terminal::text("[color=yellow]resource/lang/$locale/$file.php lacks " . count($missing) . " key(s) the core reads: " . implode(', ', array_map(fn($k) => "$prefix.$k", $missing)) . '[/color]');
            }
        }
    }

    /**
     * Dotted paths to every leaf of a nested array.
     *
     * @param array  $data
     * @param string $prefix
     * @return array
     */
    private static function keyPaths(array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $key => $value)
            if (is_array($value)) $paths = array_merge($paths, self::keyPaths($value, "$prefix$key."));
            else $paths[] = "$prefix$key";
        return $paths;
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

        $files = [];
        foreach ((array) glob("$shippedDir/*.php") as $file) {
            $name    = basename($file);
            $current = BASE_PATH . "/config/$name";

            if (!is_file($current)) {
                Terminal::text("[color=yellow]config/{$name} is new in this version - not added, copy it if you want it.[/color]");
                continue;
            }

            $files[$name] = ['shipped' => (string) file_get_contents($file), 'mine' => (string) file_get_contents($current), 'current' => $current];
        }

        # A key the new version stops shipping in one file and starts shipping in
        # another has moved - error, debug and the like went from app.php to
        # framework.php in 3.2. The value the application had is what it wants
        # kept, and without this pass it was reported as "no longer shipped" on one
        # side, "new in this version" on the other, and written as the shipped
        # default - the one way to lose a setting while claiming to merge it.
        $orphans = [];
        foreach ($files as $name => $each) {
            $located = ConfigMerge::locate($each['mine']);
            foreach (ConfigMerge::keyDrift($each['shipped'], $each['mine'])['removed'] as $key)
                if (isset($located[$key]) && $located[$key]['type'] !== 'array') $orphans[$key] = ['from' => $name, 'text' => substr($each['mine'], $located[$key]['offset'], $located[$key]['length'])];
        }

        foreach ($files as $name => $each) {
            $shipped = $each['shipped'];
            $mine    = $each['mine'];
            $current = $each['current'];

            $merged = ConfigMerge::merge($shipped, $mine);
            $drift  = ConfigMerge::keyDrift($shipped, $mine);

            # Carry a moved value into the file it now lives in, at the key's own
            # position in the merged text - right to left so no splice moves another.
            $moved = [];
            foreach ($drift['added'] as $key) if (isset($orphans[$key]) && $orphans[$key]['from'] !== $name) $moved[$key] = $orphans[$key];

            if ($moved) {
                $located = ConfigMerge::locate($merged['source']);
                $patches = [];
                foreach ($moved as $key => $orphan) if (isset($located[$key]) && $located[$key]['type'] !== 'array') $patches[$located[$key]['offset']] = [$located[$key]['length'], $orphan['text'], $key, $orphan['from']];
                krsort($patches);
                foreach ($patches as $offset => [$length, $text, $key, $from]) {
                    # debug and force-https differ per machine, and app.php is often the
                    # file kept apart per environment while framework.php is deployed.
                    # Carried, a local debug => true went live with the next deploy. They
                    # are written as null instead: framework.php then defers to app.php,
                    # which still decides, here and on the server alike.
                    if (in_array($key, self::ENVIRONMENT_KEYS, true)) {
                        $merged['source']    = substr_replace($merged['source'], 'null', $offset, $length);
                        $merged['changes'][] = "$key stays decided by $from (null here - it differs per environment; move it yourself if $from is not kept per environment)";
                    } else {
                        $merged['source']    = substr_replace($merged['source'], $text, $offset, $length);
                        $merged['changes'][] = "moved $key from $from, kept your $text";
                    }
                    $drift['added'] = array_values(array_diff($drift['added'], [$key]));
                }
            }

            # The other half: the file an environment key moved out of keeps it. The
            # merge builds on the shipped file, which no longer has the key, so it
            # dropped the line - with framework.php holding null, the setting was gone
            # on this machine. Put back at the top of the array, as it was written.
            foreach ($drift['removed'] as $key) {
                if (!in_array($key, self::ENVIRONMENT_KEYS, true) || ($orphans[$key]['from'] ?? null) !== $name || !self::movedTo($key, $files, $name)) continue;
                if (isset(ConfigMerge::locate($merged['source'])[$key])) continue;

                $merged['source']    = preg_replace('/return\s*\[\R/', '$0    ' . var_export($key, true) . ' => ' . str_replace(['\\', '$'], ['\\\\', '\\$'], $orphans[$key]['text']) . ",\n", $merged['source'], 1);
                $merged['changes'][] = "kept $key = {$orphans[$key]['text']} here - it differs per environment, so " . self::movedTo($key, $files, $name) . ' reads it from this file';
                $drift['removed']    = array_values(array_diff($drift['removed'], [$key]));
            }

            if (!$merged['changes'] && !$merged['manual'] && !$drift['added'] && !$drift['removed']) continue;

            $any = true;
            Terminal::text("[color=yellow]config/{$name}[/color]");

            foreach ($drift['added'] as $key)   Terminal::text("  [color=green]+ {$key}[/color] [color=dark-gray]new in this version[/color]");
            foreach ($drift['removed'] as $key) Terminal::text(isset($orphans[$key]) && $orphans[$key]['from'] === $name && self::movedTo($key, $files, $name) ? "  [color=dark-gray]- {$key} moved to " . self::movedTo($key, $files, $name) . "[/color]" : "  [color=dark-gray]- {$key} no longer shipped[/color]");
            foreach ($merged['changes'] as $c)  Terminal::text("  [color=dark-gray]{$c}[/color]");
            foreach ($merged['manual'] as $m) Terminal::text("  [color=red]! {$m}[/color]");

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
     * Which shipped file now carries a key that another stopped shipping, if any.
     *
     * @param string $key
     * @param array  $files
     * @param string $except
     * @return string|null
     */
    private static function movedTo(string $key, array $files, string $except): ?string
    {
        static $cache = [];
        foreach ($files as $name => $each) {
            if ($name === $except) continue;
            $cache[$name] ??= ConfigMerge::locate($each['shipped']);
            if (isset($cache[$name][$key])) return $name;
        }
        return null;
    }
    /**
     * The version the remote branch declares, read from one file rather than by
     * downloading the whole archive to find out.
     *
     * @return string|null
     */
    private static function remoteVersion(string $branch): ?string
    {
        $url  = 'https://raw.githubusercontent.com/' . self::REPO . '/' . $branch . '/zFramework/bootstrap.php';
        $body = self::fetch($url);

        if ($body === null) {
            Terminal::text('[color=red]Cannot read the remote version - ' . self::$fetchError . '.[/color]');
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
    /**
     * Why the last fetch() gave up, in words a person can act on. Null after a success.
     */
    private static ?string $fetchError = null;

    /**
     * GET a url, retrying what a retry can fix.
     *
     * api.github.com does not answer every request on every network - one
     * project saw 200, nothing, 200 on three tries in a row - and a single miss
     * ended the update with "GitHub did not answer". A dropped connection, a
     * timeout, 429 and 5xx are tried three times, a second and then two apart.
     * 403 (the API's hourly limit per IP) and 404 are answers, not accidents,
     * and are reported at once.
     *
     * @param string $url
     * @return string|null The body on 200; null with $fetchError set otherwise.
     */
    private static function fetch(string $url): ?string
    {
        self::$fetchError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) sleep($attempt - 1);

            [$body, $code, $error, $timeout] = self::request($url);
            if ($body !== null && $code === 200) {
                self::$fetchError = null;
                return $body;
            }

            self::$fetchError = match (true) {
                $timeout                  => 'GitHub did not answer within 30 seconds',
                $code === 0               => 'no connection to GitHub' . ($error ? " ($error)" : ''),
                $code === 403             => 'GitHub refused (HTTP 403) - usually the hourly API limit for this address; try again later',
                $code === 404             => 'not found on GitHub (HTTP 404) - check the branch name',
                default                   => "GitHub answered HTTP $code",
            };

            if (in_array($code, [403, 404], true)) break;
        }

        if ($attempt > 1) self::$fetchError .= ', ' . min($attempt, 3) . ' attempts';
        return null;
    }

    /**
     * One GET.
     *
     * @param string $url
     * @return array{0: ?string, 1: int, 2: string, 3: bool} body, HTTP code (0 for none), error, timed out
     */
    private static function request(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'zFramework-updater',
            ]);
            $body  = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            return [$body === false ? null : (string) $body, $code, $error, $errno === CURLE_OPERATION_TIMEDOUT];
        }

        # allow_url_fopen is off on plenty of shared hosts, which is why curl is
        # tried first rather than this.
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'zFramework-updater', 'ignore_errors' => true]]));
        $code = preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;

        return [$body === false ? null : $body, $code, $body === false ? 'request failed' : '', false];
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
