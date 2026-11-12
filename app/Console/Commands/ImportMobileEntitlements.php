<?php

namespace App\Console\Commands;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ImportMobileEntitlements extends Command
{
    protected $signature = 'app:import-mobile-entitlements {file : JSON export with an entitlements array}';

    protected $description = 'Importiert aktive WP-Kurszugänge anhand bestehender E-Mail-Konten in einen Laravel-Mandanten.';

    private const CAPABILITIES = [
        'question_bank' => 'smarttrainer',
        'learning_explanations' => 'smarttrainer',
        'exam_simulation' => 'exam',
        'navigation_trainer' => 'navigation',
        'praxis_trainer' => 'praxis',
        'video_course' => 'video',
        'radio_training' => 'funk',
        'downloads' => 'downloads',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Exportdatei nicht lesbar: {$path}");

            return self::FAILURE;
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Der Export ist kein gültiges JSON: '.$exception->getMessage());

            return self::FAILURE;
        }
        $tenantSlug = trim((string) ($payload['tenant_slug'] ?? ''));
        $items = $payload['entitlements'] ?? null;
        if ($tenantSlug === '' || ! is_array($items) || ! array_is_list($items)) {
            $this->error('Erwartet wird tenant_slug und ein entitlements-Array.');

            return self::FAILURE;
        }

        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        try {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (! $tenant) {
                throw new RuntimeException("Schule {$tenantSlug} wurde im Original nicht gefunden.");
            }
            $records = [];
            foreach ($items as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('Zugang '.($index + 1).' muss ein JSON-Objekt sein.');
                }
                $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
                $courseCode = trim((string) ($row['course_code'] ?? ''));
                $feature = (string) ($row['feature_code'] ?? '');
                $sourceKey = trim((string) ($row['source_key'] ?? ''));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $courseCode === '' || $sourceKey === '') {
                    throw new RuntimeException('Zugang '.($index + 1).' benötigt eine gültige email, course_code und source_key.');
                }
                $caps = $feature === 'all' ? null : (isset(self::CAPABILITIES[$feature]) ? [self::CAPABILITIES[$feature]] : null);
                if ($feature !== 'all' && $caps === null) {
                    throw new RuntimeException("Zugang {$sourceKey} verwendet das nicht übertragbare Feature {$feature}.");
                }
                if (($row['status'] ?? null) !== 'active') {
                    throw new RuntimeException("Zugang {$sourceKey} ist nicht aktiv; der Export darf nur aktive WP-Zugänge enthalten.");
                }

                $users = User::whereRaw('LOWER(email) = ?', [$email])->get();
                if ($users->count() !== 1) {
                    throw new RuntimeException("Für {$email} wurde kein eindeutiges bestehendes App-Konto gefunden; Nutzer zuerst anlegen/zuordnen.");
                }
                $user = $users->first();
                if ($user->status !== 'active' || $user->is_superadmin) {
                    throw new RuntimeException("Das App-Konto {$email} ist gesperrt oder Superadmin und wird nicht automatisch freigeschaltet.");
                }
                $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $courseCode)->first();
                if (! $course) {
                    throw new RuntimeException("Kurs {$courseCode} aus Zugang {$sourceKey} fehlt im Original.");
                }
                $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
                if ($membership && $membership->status !== 'active') {
                    throw new RuntimeException("Mitgliedschaft von {$email} in {$tenantSlug} ist nicht aktiv; Import gestoppt.");
                }
                $validFrom = $this->date($row['valid_from'] ?? null, $index)
                    ?? $this->date($row['user_registered'] ?? null, $index)
                    ?? $user->created_at;
                $validUntil = $this->date($row['valid_until'] ?? null, $index);
                if ($validUntil !== null && $validUntil->lessThanOrEqualTo($validFrom)) {
                    throw new RuntimeException("Zugang {$sourceKey} hat ein ungültiges Gültigkeitsfenster.");
                }
                $records[] = [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'source_key' => $sourceKey,
                    'capabilities' => $caps,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'status' => 'active',
                    'email' => $email,
                ];
            }

            DB::transaction(function () use ($records, $tenant): void {
                foreach ($records as $record) {
                    $user = User::findOrFail($record['user_id']);
                    TenantUser::withoutGlobalScopes()->firstOrCreate(
                        ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                        ['role' => 'learner', 'status' => 'active'],
                    );
                    Entitlement::withoutGlobalScopes()->updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'user_id' => $user->id,
                            'source_type' => 'wordpress',
                            'source_reference' => $record['source_key'],
                        ],
                        [
                            'course_id' => $record['course_id'],
                            'capabilities' => $record['capabilities'],
                            'valid_from' => $record['valid_from'],
                            'valid_until' => $record['valid_until'],
                            'status' => 'active',
                        ],
                    );
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info(count($records).' aktive WP-Zugänge importiert/aktualisiert; fehlende Mitgliedschaften wurden als Lernende angelegt.');

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
            throw new RuntimeException('Zugang '.($index + 1).' enthält ein ungültiges Datum.');
        }
    }
}
