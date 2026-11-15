<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\LearningGroupService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Lerngruppe (Gruppenlizenz) für eine Schule anlegen -- für den Betreiber;
 * Inhaber/Admins der Schule legen Gruppen im Admin-Bereich an. Die App
 * selbst kann Gruppen nur verwalten (Plätze sind bezahlte Zugänge). Der
 * Verwalter muss aktiver Teilnehmer der Schule sein; die Regeln stehen in
 * LearningGroupService::create(). Läuft auf der Owner-Verbindung. Der
 * Befehl legt nur an: Plätze werden erst beim Beitritt mit je einem freien
 * Kurscode der Schule pro Kurs abgerechnet (Hinweis auf den Bestand).
 */
class CreateLearningGroup extends Command
{
    protected $signature = 'app:learning-group-create
        {tenant : Slug der Schule}
        {owner : E-Mail-Adresse des Verwalters (aktiver Teilnehmer der Schule)}
        {name : Name der Gruppe}
        {--seats= : Anzahl Plätze}
        {--course=* : Kurscode, optional mit Funktion (z. B. SRC oder SRC:exam_simulation)}
        {--valid-until= : Ende der Lizenz (Datum), leer = unbefristet}
        {--source-reference= : Bestell- oder Vertragsnummer}';

    protected $description = 'Legt eine Lerngruppe (Gruppenlizenz) mit festen Plätzen für eine Schule an.';

    public function handle(LearningGroupService $groups): int
    {
        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        try {
            $tenant = Tenant::where('slug', (string) $this->argument('tenant'))->first();
            if (! $tenant) {
                throw new RuntimeException('Schule nicht gefunden.');
            }
            $owner = User::whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $this->argument('owner')))])->first();
            if (! $owner) {
                throw new RuntimeException('Der Verwalter muss aktiver Teilnehmer dieser Schule sein.');
            }
            $seats = filter_var($this->option('seats'), FILTER_VALIDATE_INT);
            if ($seats === false) {
                throw new RuntimeException('--seats muss eine Zahl zwischen 1 und '.LearningGroupService::MAX_SEATS.' sein.');
            }
            $scopes = collect($this->option('course'))->map(function (string $value) {
                [$code, $feature] = array_pad(explode(':', trim($value), 2), 2, null);

                return array_filter(['course_code' => strtoupper((string) $code), 'feature' => $feature ?: 'course']);
            })->values()->all();

            $group = $groups->create(
                $tenant, $owner, (string) $this->argument('name'), $seats, $scopes,
                LearningGroupService::validUntilFromDate($this->option('valid-until') ?: null),
                'manual', $this->option('source-reference') ?: null,
            );
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info("Lerngruppe {$group->id} angelegt ({$seats} Plätze).");
        // Der Befehl verbraucht keine Codes; das tut erst jeder Beitritt (je Kurs ein freier Kurscode der Schule).
        config(['database.default' => 'pgsql_admin']);
        try {
            foreach ($groups->codeCoverage($group) as $row) {
                $line = "Freie Kurscodes {$row['course_code']}: {$row['free']} (je Beitritt wird einer verbraucht).";
                $row['sufficient'] ? $this->line($line) : $this->warn($line.' Weniger als Plätze -- ohne freien Code kein Beitritt.');
            }
        } finally {
            config(['database.default' => $previous]);
        }

        return self::SUCCESS;
    }
}
