<?php

/**
 * Release-ZIP des BoatingSchool Form-Connectors bauen (BS-11).
 *
 *   php bin/build-libs.php --captain-pro=/pfad/zu/captain-pro   # zuerst: lib/ erzeugen
 *   php bin/build-zip.php                                        # dann: build/boatingschool-form-connector-<version>.zip
 *
 * Bricht ab, wenn lib/ fehlt oder von lib/SOURCE.md abweicht: ein ZIP ohne Lizenzprüfung würde beim Kunden nur den
 * Hinweis „lib fehlt“ zeigen (fail-closed). Ins ZIP kommen Plugin-Datei, config.php, uninstall.php, src/, lib/,
 * languages/ und wplc-catalog.json – nicht bin/, build/ oder .gitignore.
 */

declare(strict_types=1);

$plugin = dirname(__DIR__);
$slug = 'boatingschool-form-connector';

$sourceMd = @file_get_contents($plugin.'/lib/SOURCE.md');
if (! is_string($sourceMd) || preg_match_all('/^\| `([^`]+)` \| `([0-9a-f]{64})` \|$/m', $sourceMd, $rows, PREG_SET_ORDER) < 1) {
    fwrite(STDERR, "Fehler: lib/ fehlt. Erst php bin/build-libs.php --captain-pro=PFAD ausführen.\n");
    exit(2);
}
foreach ($rows as [, $file, $sha]) {
    $content = @file_get_contents($plugin.'/lib/'.$file);
    if (! is_string($content) || hash('sha256', $content) !== $sha) {
        fwrite(STDERR, "Fehler: lib/{$file} fehlt oder weicht von lib/SOURCE.md ab.\n");
        exit(2);
    }
}
$header = (string) file_get_contents($plugin.'/'.$slug.'.php');
if (preg_match('/^ \* Version:\s+(\S+)$/m', $header, $version) !== 1 || ! str_contains($header, "define('BOATINGSCHOOL_FORM_VERSION', '{$version[1]}')")) {
    fwrite(STDERR, "Fehler: Version im Kopf und BOATINGSCHOOL_FORM_VERSION stimmen nicht überein.\n");
    exit(2);
}

@mkdir($plugin.'/build', 0777, true);
$target = $plugin.'/build/'.$slug.'-'.$version[1].'.zip';
@unlink($target);
$zip = new ZipArchive;
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Fehler: {$target} lässt sich nicht schreiben.\n");
    exit(2);
}
$count = 0;
foreach ([$slug.'.php', 'config.php', 'uninstall.php', 'wplc-catalog.json', 'src', 'lib', 'languages'] as $entry) {
    $path = $plugin.'/'.$entry;
    if (is_file($path)) {
        $zip->addFile($path, $slug.'/'.$entry);
        $count++;
    } elseif (is_dir($path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), $slug.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($plugin) + 1)));
                $count++;
            }
        }
    }
}
$zip->close();
echo "{$target}: {$count} Dateien.\n";
