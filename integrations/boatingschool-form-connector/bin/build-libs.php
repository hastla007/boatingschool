<?php

/**
 * Add-on-Kernel und Lizenzclient beim Bau aus captain-pro in lib/ schreiben (BS-11, Entscheidung 17).
 *
 *   php bin/build-libs.php --captain-pro=/pfad/zu/captain-pro            # schreiben
 *   php bin/build-libs.php --captain-pro=/pfad/zu/captain-pro --check    # nur prüfen
 *
 * Warum beim Bau: dieses Repository ist öffentlich (BS-03); Kernel und Lizenzclient sind proprietärer Code aus dem
 * privaten Repository captain-pro und dürfen hier nicht eingecheckt werden. lib/ steht deshalb in .gitignore und
 * entsteht nur in einem Bau-Checkout; bin/build-zip.php packt es ins Release-ZIP. Ohne lib/ bleibt das Plugin inaktiv
 * (fail-closed, Hinweis im Admin).
 *
 * Umgeschrieben werden nur Namespace (BoatingschoolFormConnector\Kernel bzw. \Licensing) und Textdomain
 * (boatingschool-form-connector) – mit build/lib/ClientExporter.php von captain-pro selbst, sonst bleibt der Code
 * unverändert. lib/SOURCE.md nennt Commit, Versionen und SHA-256 je Datei.
 *
 * Kein Netz, kein Composer: nur PHP und git. Der captain-pro-Checkout wird nur gelesen und muss in build/lib und
 * packages sauber sein. Exit-Code: 0 = in Ordnung, 1 = Abweichungen (--check), 2 = Aufruf- oder Eingabefehler.
 */

declare(strict_types=1);
use CaptainPro\Build\ClientExporter;

const BSFC_SLUG = 'boatingschool-form-connector';
const BSFC_NAMESPACE = 'BoatingschoolFormConnector';

exit(bsfc_main($argv));

/** @param string[] $argv */
function bsfc_main(array $argv): int
{
    $options = getopt('', ['captain-pro:', 'check', 'help']);
    if (isset($options['help']) || ! isset($options['captain-pro'])) {
        fwrite(STDERR, "Aufruf: php bin/build-libs.php --captain-pro=PFAD [--check]\n");

        return isset($options['help']) ? 0 : 2;
    }
    $plugin = dirname(__DIR__);
    $source = rtrim((string) $options['captain-pro'], '/');
    try {
        $meta = bsfc_read_source($source);
        $tmp = sys_get_temp_dir().'/bsfc-lib-'.bin2hex(random_bytes(6));
        mkdir($tmp, 0777, true);
        try {
            ClientExporter::export_client($source.'/packages/license-client', $tmp.'/licensing', BSFC_NAMESPACE.'\\Licensing', BSFC_SLUG);
            ClientExporter::export_kernel($source.'/packages/addon-kernel/src', $tmp.'/kernel', BSFC_NAMESPACE.'\\Kernel', BSFC_NAMESPACE.'\\Licensing', BSFC_SLUG);
            file_put_contents($tmp.'/index.php', "<?php\n// Silence is golden.\n");
            $wanted = [];
            foreach (bsfc_files($tmp) as $file) {
                $wanted[$file] = (string) file_get_contents($tmp.'/'.$file);
            }
            $wanted['SOURCE.md'] = bsfc_source_md($meta, $wanted);
        } finally {
            bsfc_remove($tmp);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fehler: '.$e->getMessage()."\n");

        return 2;
    }

    $lib = $plugin.'/lib';
    $present = is_dir($lib) ? bsfc_files($lib) : [];
    $differences = [];
    foreach ($wanted as $file => $content) {
        if (! in_array($file, $present, true)) {
            $differences[] = "{$file} fehlt";
        } elseif ((string) file_get_contents($lib.'/'.$file) !== $content) {
            $differences[] = "{$file} anders";
        }
    }
    foreach (array_diff($present, array_keys($wanted)) as $file) {
        $differences[] = "{$file} überzählig";
    }

    printf("captain-pro %s, Kernel %s, Lizenzclient %s\n", substr($meta['commit'], 0, 12), $meta['kernel_version'], $meta['client_version']);
    if (isset($options['check'])) {
        foreach ($differences as $line) {
            echo "  weicht ab: {$line}\n";
        }
        echo $differences === [] ? "lib/ in Ordnung.\n" : "Abweichungen. Schreiben mit: php bin/build-libs.php --captain-pro=PFAD\n";

        return $differences === [] ? 0 : 1;
    }
    if ($differences === []) {
        echo "Nichts zu ändern.\n";

        return 0;
    }
    bsfc_remove($lib);
    foreach ($wanted as $file => $content) {
        if (! is_dir(dirname($lib.'/'.$file))) {
            mkdir(dirname($lib.'/'.$file), 0777, true);
        }
        file_put_contents($lib.'/'.$file, $content);
    }
    echo count($wanted)." Datei(en) nach lib/ geschrieben (nicht einchecken, siehe .gitignore).\n";

    return 0;
}

/** @return array{commit:string,date:string,kernel_version:string,client_version:string,wplc_commit:string} */
function bsfc_read_source(string $path): array
{
    foreach (['build/lib/ClientExporter.php', 'packages/addon-kernel/src/load.php', 'packages/addon-kernel/src/Addon.php', 'packages/license-client/load.php', 'packages/license-client/LicenseManager.php', 'packages/license-client/SOURCE.md'] as $file) {
        if (! is_file($path.'/'.$file)) {
            throw new RuntimeException("Kein passender captain-pro-Checkout: {$path}/{$file} fehlt.");
        }
    }
    $commit = bsfc_git($path, ['rev-parse', 'HEAD']);
    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        throw new RuntimeException("{$path} ist kein git-Checkout.");
    }
    $dirty = bsfc_git($path, ['status', '--porcelain', '--', 'build/lib', 'packages']);
    if ($dirty !== '') {
        throw new RuntimeException("captain-pro hat lokale Änderungen in build/lib oder packages:\n".$dirty);
    }
    $kernel = (string) file_get_contents($path.'/packages/addon-kernel/src/Addon.php');
    $client = (string) file_get_contents($path.'/packages/license-client/LicenseManager.php');
    $origin = (string) file_get_contents($path.'/packages/license-client/SOURCE.md');
    if (preg_match("/const VERSION\\s*=\\s*'([^']+)'/", $kernel, $kv) !== 1 || preg_match("/const VERSION\\s*=\\s*'([^']+)'/", $client, $cv) !== 1) {
        throw new RuntimeException('Versionen von Kernel oder Lizenzclient nicht lesbar.');
    }
    preg_match('/^\| Commit \| `([0-9a-f]{40})` \|/m', $origin, $wc);
    require_once $path.'/build/lib/ClientExporter.php';

    return [
        'commit' => $commit,
        'date' => bsfc_git($path, ['log', '-1', '--format=%cI', 'HEAD']),
        'kernel_version' => $kv[1],
        'client_version' => $cv[1],
        'wplc_commit' => $wc[1] ?? 'unbekannt',
    ];
}

/** @param array<string,string> $files */
function bsfc_source_md(array $meta, array $files): string
{
    ksort($files);
    $table = '';
    foreach ($files as $file => $content) {
        $table .= '| `'.$file.'` | `'.hash('sha256', $content)."` |\n";
    }

    return "# lib/ – Herkunft\n\n"
        ."**Nicht von Hand ändern, nicht einchecken.** Erzeugt von `bin/build-libs.php` aus captain-pro (privat).\n\n"
        ."| | |\n| --- | --- |\n"
        .'| captain-pro (`hastla007/captain-pro`) | `'.$meta['commit'].'` ('.$meta['date'].") |\n"
        .'| Add-on-Kernel (`packages/addon-kernel`, `Addon::VERSION`) | '.$meta['kernel_version']." |\n"
        .'| Lizenzclient (`packages/license-client`, `LicenseManager::VERSION`) | '.$meta['client_version']." |\n"
        .'| wp-license, Herkunft des Lizenzclients | `'.$meta['wplc_commit']."` |\n"
        .'| Namespace | `'.BSFC_NAMESPACE.'\\Kernel`, `'.BSFC_NAMESPACE."\\Licensing` |\n"
        .'| Textdomain | `'.BSFC_SLUG."` |\n\n"
        ."## Dateien\n\n| Datei (unter `lib/`) | SHA-256 |\n| --- | --- |\n".$table;
}

/** @param string[] $args */
function bsfc_git(string $path, array $args): string
{
    $command = 'git -C '.escapeshellarg($path).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';

    return trim((string) shell_exec($command));
}

/** @return string[] */
function bsfc_files(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
    }
    sort($files);

    return $files;
}

function bsfc_remove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
