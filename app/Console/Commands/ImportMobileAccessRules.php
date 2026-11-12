<?php

namespace App\Console\Commands;

use App\Models\CourseAccessRule;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ImportMobileAccessRules extends Command
{
    protected $signature = 'app:import-mobile-access-rules {file : JSON export with a rules array}';

    protected $description = 'Importiert Free-, Limited- und Drip-Zugangsregeln aus dem WordPress-Export in die native Mobile-API.';

    private const FEATURES = [
        'question_bank', 'learning_explanations', 'exam_simulation', 'navigation_trainer',
        'praxis_trainer', 'video_course', 'radio_training', 'device_simulation', 'downloads',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Exportdatei nicht lesbar: {$path}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Der Export ist kein gültiges JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        $rules = is_array($payload) ? ($payload['rules'] ?? null) : null;
        if (! is_array($rules) || ! array_is_list($rules)) {
            $this->error('Erwartet wird ein JSON-Objekt mit einem rules-Array.');

            return self::FAILURE;
        }

        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);

        try {
            $records = [];
            foreach ($rules as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('Regel '.($index + 1).' muss ein JSON-Objekt sein.');
                }
                $tenantSlug = trim((string) ($row['tenant_slug'] ?? ''));
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $feature = (string) ($row['feature_code'] ?? '');
                $type = (string) ($row['access_type'] ?? '');
                $moduleCode = isset($row['module_code']) ? trim((string) $row['module_code']) : null;
                $limit = $row['limit_value'] ?? null;

                if ($tenantSlug === '' || $courseCode === '' || ! in_array($feature, self::FEATURES, true)) {
                    throw new RuntimeException('Regel '.($index + 1).' benötigt tenant_slug, course_code und einen bekannten feature_code.');
                }
                if (! in_array($type, ['free', 'free_limited', 'drip'], true)) {
                    throw new RuntimeException('Regel '.($index + 1).' hat einen unbekannten access_type.');
                }
                if (($type === 'drip' && ($moduleCode === null || $moduleCode === '' || ! is_numeric($limit) || (int) $limit < 1))
                    || ($type === 'free_limited' && (! is_numeric($limit) || (int) $limit < 0))
                    || ($type === 'free' && $limit !== null)
                    || ($type !== 'drip' && $moduleCode !== null && $moduleCode !== '')) {
                    throw new RuntimeException('Regel '.($index + 1).' hat eine ungültige Kombination aus access_type, module_code und limit_value.');
                }

                $tenant = Tenant::where('slug', $tenantSlug)->first();
                $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $courseCode)->first();
                if (! $tenant || ! $course) {
                    throw new RuntimeException("Regel ".($index + 1).": Schule {$tenantSlug} oder Kurs {$courseCode} wurde im Original nicht gefunden.");
                }

                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                if ($sourceKey === '') {
                    $sourceKey = implode(':', ['wp', $tenantSlug, $courseCode, $feature, $type, $moduleCode ?: 'course']);
                }
                if (strlen($sourceKey) > 160) {
                    throw new RuntimeException('Regel '.($index + 1).': source_key darf höchstens 160 Zeichen lang sein.');
                }

                $records[] = [
                    'tenant_id' => $tenant->id,
                    'course_id' => $course->id,
                    'feature_code' => $feature,
                    'access_type' => $type,
                    'module_code' => $moduleCode ?: null,
                    'limit_value' => $limit === null ? null : (int) $limit,
                    'valid_from' => $this->date($row['valid_from'] ?? null, $index),
                    'valid_until' => $this->date($row['valid_until'] ?? null, $index),
                    'source_key' => $sourceKey,
                ];
            }

            DB::transaction(function () use ($records): void {
                foreach ($records as $record) {
                    CourseAccessRule::updateOrCreate(
                        ['tenant_id' => $record['tenant_id'], 'source_key' => $record['source_key']],
                        $record,
                    );
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info(count($records).' Zugangsregel(n) importiert bzw. aktualisiert.');

        return self::SUCCESS;
    }

    private function date(mixed $value, int $index): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new RuntimeException('Regel '.($index + 1).' enthält ein ungültiges Datum.');
        }
    }
}
