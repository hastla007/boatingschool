<?php

namespace App\Console\Commands;

use App\Models\CourseDefinition;
use App\Models\CourseFile;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportMobileCourseFiles extends Command
{
    protected $signature = 'app:import-mobile-course-files {manifest : JSON manifest with a files array} {source-root : Directory containing the staged WordPress media files}';

    protected $description = 'Übernimmt Kursdateien aus einem WordPress-Medienexport in den geschützten Laravel-Storage.';

    private const CAPABILITIES = ['smarttrainer', 'favorites', 'exam', 'video', 'navigation', 'praxis', 'funk', 'downloads'];

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
                $tenantSlug = trim((string) ($row['tenant_slug'] ?? ''));
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                $relativeSource = (string) ($row['file'] ?? '');
                $category = (string) ($row['category'] ?? '');
                if ($tenantSlug === '' || $courseCode === '' || $sourceKey === '' || strlen($sourceKey) > 160 || $relativeSource === '') {
                    throw new RuntimeException('Datei '.($index + 1).' benötigt tenant_slug, course_code, source_key und file.');
                }
                if (! in_array($category, ['download', 'section', 'chart'], true)) {
                    throw new RuntimeException('Datei '.($index + 1).' hat eine unbekannte category.');
                }
                $required = $row['required_capability'] ?? null;
                if ($required !== null && ! in_array($required, self::CAPABILITIES, true)) {
                    throw new RuntimeException('Datei '.($index + 1).' hat eine unbekannte required_capability.');
                }
                $sectionKey = trim((string) ($row['section_key'] ?? ''));
                if ($category === 'section' && $sectionKey === '') {
                    $sectionKey = 'general';
                }
                if ($category === 'download' && $sectionKey !== '') {
                    throw new RuntimeException('Download-Datei '.($index + 1).' darf keinen section_key enthalten.');
                }

                $tenant = Tenant::where('slug', $tenantSlug)->first();
                $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $courseCode)->first();
                if (! $tenant || ! $course) {
                    throw new RuntimeException("Datei ".($index + 1).": Schule {$tenantSlug} oder Kurs {$courseCode} wurde nicht gefunden.");
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
                $target = 'course-files/'.$safeCourse.'/'.hash('sha256', $courseCode.':'.$sourceKey).'-'.$sourceHash.$extension;
                $records[] = [
                    'tenant_id' => $tenant->id,
                    'course_id' => $course->id,
                    'category' => $category,
                    'section_key' => $category === 'section' ? $sectionKey : null,
                    'title' => trim((string) ($row['title'] ?? '')),
                    'description' => isset($row['description']) ? (string) $row['description'] : null,
                    'storage_path' => $target,
                    'mime_type' => $row['mime_type'] ?? (mime_content_type($sourcePath) ?: 'application/octet-stream'),
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
                $alreadyExists = Storage::disk('local')->exists($record['storage_path']);
                $stream = fopen($record['source_path'], 'rb');
                if ($stream === false || ! Storage::disk('local')->put($record['storage_path'], $stream)) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw new RuntimeException('Eine Quelldatei ließ sich nicht in den Laravel-Storage kopieren.');
                }
                fclose($stream);
                $storedHash = hash_file('sha256', Storage::disk('local')->path($record['storage_path']));
                if (! is_string($storedHash) || ! hash_equals($record['sha256'], $storedHash)) {
                    throw new RuntimeException('Eine Kursdatei wurde im Laravel-Storage nicht vollständig gespeichert.');
                }
                if (! $alreadyExists) {
                    $written[] = $record['storage_path'];
                }
            }

            DB::transaction(function () use ($records): void {
                foreach ($records as $record) {
                    unset($record['source_path'], $record['tenant_id'], $record['sha256']);
                    CourseFile::updateOrCreate(['course_id' => $record['course_id'], 'source_key' => $record['source_key']], $record);
                }
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk('local')->delete($path);
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info(count($records).' Kursdatei(en) kopiert und registriert.');

        return self::SUCCESS;
    }
}
