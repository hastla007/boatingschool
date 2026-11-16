<?php

namespace App\Console\Commands;

use App\Api\CourseAccess;
use App\Models\CourseDefinition;
use App\Models\CourseFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Kursdateien aus dem WP-Export übernehmen (Manifest + bereitgestellte
 * Dateien). Kursdateien sind plattformweit (ohne Schulbezug); ein
 * tenant_slug im Manifest wird ignoriert. Erneuter Lauf aktualisiert über
 * (course_code, source_key).
 */
class ImportMobileCourseFiles extends Command
{
    protected $signature = 'app:import-mobile-course-files {manifest : JSON manifest with a files array} {source-root : Directory containing the staged WordPress media files}';

    protected $description = 'Übernimmt Kursdateien aus einem WordPress-Medienexport in den geschützten Laravel-Storage.';

    public function handle(): int
    {
        $manifestPath = $this->argument('manifest');
        $sourceRoot = realpath($this->argument('source-root'));
        if (! is_file($manifestPath) || ! is_readable($manifestPath) || $sourceRoot === false || ! is_dir($sourceRoot)) {
            $this->error('Manifest oder Quellverzeichnis ist nicht lesbar.');

            return self::FAILURE;
        }

        try {
            $payload = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error('Das Manifest ist kein gültiges JSON: '.$exception->getMessage());

            return self::FAILURE;
        }
        $files = is_array($payload) ? ($payload['files'] ?? null) : null;
        if (! is_array($files) || ! array_is_list($files)) {
            $this->error('Erwartet wird ein JSON-Objekt mit einem files-Array.');

            return self::FAILURE;
        }

        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        $written = [];
        try {
            $records = [];
            foreach ($files as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('Datei '.($index + 1).' muss ein JSON-Objekt sein.');
                }
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                $relativeSource = (string) ($row['file'] ?? '');
                $category = (string) ($row['category'] ?? '');
                $title = trim((string) ($row['title'] ?? ''));
                if ($courseCode === '' || $sourceKey === '' || strlen($sourceKey) > 160 || $relativeSource === '' || $title === '' || mb_strlen($title) > 190) {
                    throw new RuntimeException('Datei '.($index + 1).' benötigt course_code, source_key, file und title (höchstens 190 Zeichen).');
                }
                if (! in_array($category, CourseFile::CATEGORIES, true)) {
                    throw new RuntimeException('Datei '.($index + 1).' hat eine unbekannte category.');
                }
                $required = $row['required_capability'] ?? null;
                if ($required !== null && ! in_array($required, CourseAccess::ENTITLEMENT_CAPABILITIES, true)) {
                    throw new RuntimeException('Datei '.($index + 1).' hat eine unbekannte required_capability.');
                }
                $sectionKey = trim((string) ($row['section_key'] ?? ''));
                if ($category === 'section' && $sectionKey === '') {
                    $sectionKey = 'general';
                }
                if ($category === 'download' && $sectionKey !== '') {
                    throw new RuntimeException('Download-Datei '.($index + 1).' darf keinen section_key enthalten.');
                }

                $mime = $row['mime_type'] ?? null;
                if ($mime !== null && (! is_string($mime) || ! preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mime))) {
                    throw new RuntimeException('Datei '.($index + 1).' hat einen ungültigen mime_type.');
                }
                $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $courseCode)->first();
                if (! $course) {
                    throw new RuntimeException('Datei '.($index + 1).": Kurs {$courseCode} wurde nicht gefunden.");
                }
                $sourcePath = realpath($sourceRoot.DIRECTORY_SEPARATOR.$relativeSource);
                if ($sourcePath === false || ! str_starts_with($sourcePath, $sourceRoot.DIRECTORY_SEPARATOR) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
                    throw new RuntimeException('Datei '.($index + 1).' liegt nicht sicher im Quellverzeichnis oder ist nicht lesbar.');
                }

                $sourceHash = hash_file('sha256', $sourcePath);
                if (! is_string($sourceHash)) {
                    throw new RuntimeException('Datei '.($index + 1).' konnte nicht geprüft werden.');
                }
                $expectedHash = strtolower(trim((string) ($row['sha256'] ?? '')));
                if ($expectedHash !== '' && (! preg_match('/^[a-f0-9]{64}$/', $expectedHash) || ! hash_equals($expectedHash, $sourceHash))) {
                    throw new RuntimeException('Datei '.($index + 1).' stimmt nicht mit der Prüfsumme aus dem WP-Export überein.');
                }
                $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
                $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.'.$extension : '';
                $safeCourse = preg_replace('/[^A-Za-z0-9_-]/', '_', $courseCode);
                $target = CourseFile::DIRECTORY.'/'.$safeCourse.'/'.hash('sha256', $courseCode.':'.$sourceKey).'-'.$sourceHash.$extension;
                $records[] = [
                    'course_id' => $course->id,
                    'category' => $category,
                    'section_key' => $category === 'section' ? $sectionKey : null,
                    'title' => $title,
                    'description' => isset($row['description']) ? (string) $row['description'] : null,
                    'storage_path' => $target,
                    'mime_type' => $mime ? strtolower($mime) : (mime_content_type($sourcePath) ?: 'application/octet-stream'),
                    'size_bytes' => filesize($sourcePath),
                    'required_capability' => $required,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'published' => (bool) ($row['published'] ?? true),
                    'source_key' => $sourceKey,
                    'source_path' => $sourcePath,
                    'sha256' => $sourceHash,
                ];
            }

            foreach ($records as $record) {
                $alreadyExists = Storage::disk(CourseFile::DISK)->exists($record['storage_path']);
                $stream = fopen($record['source_path'], 'rb');
                if ($stream === false || ! Storage::disk(CourseFile::DISK)->put($record['storage_path'], $stream)) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw new RuntimeException('Eine Quelldatei ließ sich nicht in den Laravel-Storage kopieren.');
                }
                fclose($stream);
                if (! $alreadyExists) {
                    $written[] = $record['storage_path'];
                }
                $storedHash = hash_file('sha256', Storage::disk(CourseFile::DISK)->path($record['storage_path']));
                if (! is_string($storedHash) || ! hash_equals($record['sha256'], $storedHash)) {
                    throw new RuntimeException('Eine Kursdatei wurde im Laravel-Storage nicht vollständig gespeichert.');
                }
            }

            DB::transaction(function () use ($records): void {
                foreach ($records as $record) {
                    unset($record['source_path'], $record['sha256']);
                    CourseFile::updateOrCreate(['course_id' => $record['course_id'], 'source_key' => $record['source_key']], $record);
                }
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk(CourseFile::DISK)->delete($path);
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        // Kurs-Funktionen (capabilities: downloads) neu berechnen lassen.
        CourseAccess::bumpContentVersion();
        $this->info(count($records).' Kursdatei(en) kopiert und registriert.');

        return self::SUCCESS;
    }
}
