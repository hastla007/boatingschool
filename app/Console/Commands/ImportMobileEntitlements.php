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

/**
 * Übernimmt Kurszugänge aus dem WP-Export (wp bootspruefung export entitlements).
 *
 * Format 1 (ohne format_version): nur aktive Zugänge, alles andere bricht ab.
 * Format 2 (format_version 2, WP T46): aktive und gesperrte Zugänge, auch
 * Verlängerungen mit späterem Beginn, mit Herkunft (source, group_id,
 * order_id) und getrennten Simulator-Zugängen. Regeln der WP-Seite:
 * gesperrte Zugänge nicht freischalten, spätere Zugänge erst ab valid_from.
 *
 * Idempotent über source_key (= entitlement.source_reference mit source_type
 * "wordpress"): ein erneuter Lauf aktualisiert Status, Zeitraum, Funktionen
 * und Herkunft. Alles oder nichts in einer Transaktion; --dry-run prüft
 * dieselben Regeln und zeigt nur an, was passieren würde.
 */
class ImportMobileEntitlements extends Command
{
    protected $signature = 'app:import-mobile-entitlements
        {file : JSON export with an entitlements array}
        {--dry-run : Nur prüfen und anzeigen, was importiert würde; nichts speichern}';

    protected $description = 'Importiert WP-Kurszugänge (Format 1 und 2) anhand bestehender E-Mail-Konten in einen Laravel-Mandanten.';

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

    private const SOURCES = ['woocommerce', 'group', 'manual', 'voucher'];

    /** Konten ohne Mitgliedschaft in der Zielschule (werden als Lernende angelegt). */
    private int $newMembers = 0;

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
        if (! is_array($payload)) {
            $this->error('Erwartet wird tenant_slug und ein entitlements-Array.');

            return self::FAILURE;
        }
        $format = $payload['format_version'] ?? 1;
        if (! in_array($format, [1, 2], true)) {
            $this->error('Unbekannte format_version '.json_encode($format).'; unterstützt werden 1 und 2.');

            return self::FAILURE;
        }
        $tenantSlug = trim((string) ($payload['tenant_slug'] ?? ''));
        $items = $payload['entitlements'] ?? null;
        if ($tenantSlug === '' || ! is_array($items) || ! array_is_list($items)) {
            $this->error('Erwartet wird tenant_slug und ein entitlements-Array.');

            return self::FAILURE;
        }
        // Simulator-Zugänge (device_simulation) werden bewusst nicht
        // übernommen: Die Gerätesimulation Funk (App\Support\Geraetesimulation,
        // GeraetesimulationController) hat hier weder eine eigene Capability
        // (CourseAccess::ENTITLEMENT_CAPABILITIES) noch ein eigenes Produkt --
        // sie hängt am vollen Kurszugang (EntitlementService::hasAccess, also
        // capabilities = null) und ist zusätzlich über
        // config('funk.geraetesimulation') freigeschaltet (Beta). Eine
        // Zuordnung auf vollen Zugang würde den ganzen Kurs freischalten, eine
        // auf "funk" öffnet die Simulation gar nicht. Deshalb nur zählen und in
        // der Ausgabe melden.
        $simulator = $format === 2 ? ($payload['device_simulation_entitlements'] ?? []) : [];
        if (! is_array($simulator) || ! array_is_list($simulator)) {
            $this->error('device_simulation_entitlements muss ein Array sein.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        try {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (! $tenant) {
                throw new RuntimeException("Schule {$tenantSlug} wurde im Original nicht gefunden.");
            }
            $records = $this->validateRows($items, $format, $tenant, $tenantSlug);

            if (! $dryRun) {
                DB::transaction(function () use ($records, $tenant): void {
                    foreach ($records as $record) {
                        TenantUser::withoutGlobalScopes()->firstOrCreate(
                            ['tenant_id' => $tenant->id, 'user_id' => $record['user_id']],
                            ['role' => 'learner', 'status' => 'active'],
                        );
                        if (in_array($record['action'], ['kept', 'unchanged'], true)) {
                            continue;
                        }
                        if ($record['existing']) {
                            $record['existing']->save();

                            continue;
                        }
                        Entitlement::withoutGlobalScopes()->create([
                            'tenant_id' => $tenant->id,
                            'user_id' => $record['user_id'],
                            'source_type' => 'wordpress',
                            'source_reference' => $record['source_key'],
                        ] + $record['attributes']);
                    }
                });
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->report($records, $format, count($simulator), $dryRun);

        return self::SUCCESS;
    }

    /**
     * Prüft alle Zeilen vor dem ersten Schreiben und ermittelt je Zeile, was
     * passiert: new, updated, unchanged oder kept (in Laravel gesperrt bzw.
     * widerrufen, bleibt so).
     *
     * @return list<array<string, mixed>>
     */
    private function validateRows(array $items, int $format, Tenant $tenant, string $tenantSlug): array
    {
        $records = [];
        $seen = [];
        $newMembers = [];
        foreach ($items as $index => $row) {
            $number = $index + 1;
            if (! is_array($row)) {
                throw new RuntimeException("Zugang {$number} muss ein JSON-Objekt sein.");
            }
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $courseCode = trim((string) ($row['course_code'] ?? ''));
            $feature = (string) ($row['feature_code'] ?? '');
            $sourceKey = trim((string) ($row['source_key'] ?? ''));
            $label = "Zugang {$number} (".($sourceKey !== '' ? $sourceKey : 'ohne source_key').')';
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $courseCode === '' || $sourceKey === '') {
                throw new RuntimeException("{$label} benötigt eine gültige email, course_code und source_key.");
            }
            if (isset($seen[$sourceKey])) {
                throw new RuntimeException("{$label}: source_key steht bereits in Zugang {$seen[$sourceKey]}.");
            }
            $seen[$sourceKey] = $number;
            // "all" = voller Kurszugang (capabilities null), sonst die passende Funktion.
            $caps = $feature === 'all' ? null : (isset(self::CAPABILITIES[$feature]) ? [self::CAPABILITIES[$feature]] : null);
            if ($feature !== 'all' && $caps === null) {
                throw new RuntimeException("{$label} verwendet das nicht übertragbare Feature {$feature}.");
            }
            $status = $row['status'] ?? null;
            $meta = null;
            if ($format === 1) {
                if ($status !== 'active') {
                    throw new RuntimeException("{$label} ist nicht aktiv; der Export darf nur aktive WP-Zugänge enthalten.");
                }
            } else {
                if (! in_array($status, ['active', 'suspended'], true)) {
                    throw new RuntimeException("{$label} hat den unbekannten Status ".json_encode($status).' (erwartet active oder suspended).');
                }
                $source = $row['source'] ?? null;
                if (! in_array($source, self::SOURCES, true)) {
                    throw new RuntimeException("{$label} hat die unbekannte Herkunft ".json_encode($source).' (erwartet '.implode(', ', self::SOURCES).').');
                }
                foreach (['group_id', 'order_id'] as $key) {
                    $value = $row[$key] ?? null;
                    if ($value !== null && (! is_int($value) || $value < 1)) {
                        throw new RuntimeException("{$label}: {$key} muss eine positive Zahl oder null sein.");
                    }
                }
                // Herkunft für Support und spätere Abgleiche; status merkt sich,
                // ob eine Sperre aus WP kam (siehe changedLocally()).
                $meta = [
                    'source' => $source,
                    'group_id' => $row['group_id'] ?? null,
                    'order_id' => $row['order_id'] ?? null,
                    'status' => $status,
                ];
            }

            $users = User::whereRaw('LOWER(email) = ?', [$email])->get();
            if ($users->count() !== 1) {
                throw new RuntimeException("{$label}: Für {$email} wurde kein eindeutiges bestehendes App-Konto gefunden; Nutzer zuerst anlegen/zuordnen.");
            }
            $user = $users->first();
            if ($user->status !== 'active' || $user->is_superadmin) {
                throw new RuntimeException("{$label}: Das App-Konto {$email} ist gesperrt oder Superadmin und wird nicht automatisch freigeschaltet.");
            }
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $courseCode)->first();
            if (! $course) {
                throw new RuntimeException("{$label}: Kurs {$courseCode} fehlt im Original.");
            }
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
            if ($membership && $membership->status !== 'active') {
                throw new RuntimeException("{$label}: Mitgliedschaft von {$email} in {$tenantSlug} ist nicht aktiv; Import gestoppt.");
            }
            if (! $membership) {
                $newMembers[$user->id] = true;
            }
            $validFrom = $this->date($row['valid_from'] ?? null, $label)
                ?? $this->date($row['user_registered'] ?? null, $label)
                ?? $user->created_at;
            $validUntil = $this->date($row['valid_until'] ?? null, $label);
            if ($validUntil !== null && $validUntil->lessThanOrEqualTo($validFrom)) {
                throw new RuntimeException("{$label} hat ein ungültiges Gültigkeitsfenster.");
            }

            $attributes = [
                'course_id' => $course->id,
                'capabilities' => $caps,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                // Gesperrte WP-Zugänge bleiben gesperrt; ein späterer Beginn
                // bleibt als valid_from stehen (Entitlement::scopeCurrentlyActive
                // zählt den Zugang erst ab dann).
                'status' => $status,
            ];
            // Format 1 kennt keine Herkunft; dort bleibt import_meta unberührt.
            if ($format === 2) {
                $attributes['import_meta'] = $meta;
            }

            $existing = Entitlement::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', 'wordpress')
                ->where('source_reference', $sourceKey)
                ->first();
            $action = 'new';
            if ($existing) {
                if ($existing->user_id !== $user->id) {
                    throw new RuntimeException("{$label}: Der Quellschlüssel wurde bereits für ein anderes App-Konto importiert.");
                }
                if ($this->changedLocally($existing, $status)) {
                    $action = 'kept';
                } else {
                    $originalMeta = $existing->import_meta;
                    $existing->fill($attributes);
                    // jsonb ordnet Schlüssel um; Herkunft daher ohne Reihenfolge vergleichen.
                    $metaChanged = $format === 2 && $originalMeta != $meta;
                    $action = $existing->isDirty(['course_id', 'capabilities', 'valid_from', 'valid_until', 'status']) || $metaChanged
                        ? 'updated' : 'unchanged';
                }
            }

            $records[] = [
                'number' => $number,
                'source_key' => $sourceKey,
                'email' => $email,
                'user_id' => $user->id,
                'course_code' => $courseCode,
                'feature' => $feature,
                'source' => $meta['source'] ?? null,
                'attributes' => $attributes,
                'existing' => $existing,
                'action' => $action,
            ];
        }
        $this->newMembers = count($newMembers);

        return $records;
    }

    /**
     * In Laravel widerrufen, oder dort (nicht durch den Import) gesperrt und
     * WP meldet aktiv? Dann schaltet der Import den Zugang nicht wieder frei.
     */
    private function changedLocally(Entitlement $existing, string $status): bool
    {
        if ($existing->status === 'revoked') {
            return true;
        }

        return $existing->status === 'suspended' && $status === 'active'
            && ($existing->import_meta['status'] ?? null) !== 'suspended';
    }

    /** @param list<array<string, mixed>> $records */
    private function report(array $records, int $format, int $simulatorCount, bool $dryRun): void
    {
        $count = fn (callable $filter) => count(array_filter($records, $filter));
        $verb = $dryRun ? 'würden' : 'wurden';

        if ($dryRun) {
            $actions = ['new' => 'neu', 'updated' => 'aktualisieren', 'unchanged' => 'unverändert', 'kept' => 'nicht ändern (in Laravel gesperrt/widerrufen)'];
            $this->table(
                ['#', 'source_key', 'E-Mail', 'Kurs', 'Funktion', 'Status', 'gültig ab', 'gültig bis', 'Herkunft', 'Aktion'],
                array_map(fn (array $r) => [
                    $r['number'], $r['source_key'], $r['email'], $r['course_code'], $r['feature'],
                    $r['attributes']['status'],
                    $r['attributes']['valid_from']->toIso8601ZuluString(),
                    $r['attributes']['valid_until']?->toIso8601ZuluString() ?? '–',
                    $r['source'] ?? '–',
                    $actions[$r['action']],
                ], $records),
            );
            $this->warn('Probelauf (--dry-run): nichts gespeichert.');
        }

        if ($format === 1) {
            $this->info($dryRun
                ? count($records).' aktive WP-Zugänge würden importiert/aktualisiert; '.$this->newMembers.' fehlende Mitgliedschaften würden als Lernende angelegt.'
                : count($records).' aktive WP-Zugänge importiert/aktualisiert; fehlende Mitgliedschaften wurden als Lernende angelegt.');
        } else {
            $this->info(sprintf(
                '%d WP-Zugänge (Format 2): %d neu, %d aktualisiert, %d unverändert; %d fehlende Mitgliedschaften %s als Lernende angelegt.',
                count($records),
                $count(fn ($r) => $r['action'] === 'new'),
                $count(fn ($r) => $r['action'] === 'updated'),
                $count(fn ($r) => $r['action'] === 'unchanged'),
                $this->newMembers,
                $verb,
            ));
            $now = now();
            $this->line(sprintf(
                'Davon gesperrt (suspended, nicht freigeschaltet): %d; mit späterem Beginn (erst ab valid_from aktiv): %d.',
                $count(fn ($r) => $r['attributes']['status'] === 'suspended'),
                $count(fn ($r) => $r['attributes']['valid_from']->greaterThan($now)),
            ));
            $groups = $count(fn ($r) => $r['source'] === 'group');
            if ($groups > 0) {
                $this->line("Gruppenplätze: {$groups} {$verb} als einzelne Zugänge der Lernenden übernommen (Herkunft in import_meta); Lerngruppen werden nicht angelegt.");
            }
            $this->line("Simulator-Zugänge (device_simulation): {$simulatorCount} übersprungen – die Gerätesimulation hat hier keinen eigenen Zugang, sie gehört zum vollen Kurszugang.");
        }
        $kept = $count(fn ($r) => $r['action'] === 'kept');
        if ($kept > 0) {
            $this->warn("{$kept} Zugänge sind in Laravel gesperrt oder widerrufen und {$verb} nicht verändert.");
        }
    }

    private function date(mixed $value, string $label): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new RuntimeException("{$label} enthält ein ungültiges Datum.");
        }
    }
}
