<?php

namespace App\Console\Commands;

use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LearningGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lerngruppe (Gruppenlizenz) für eine Schule anlegen. Bewusst nur für den
 * Betreiber: die App kann Gruppen verwalten, aber keine anlegen (Plätze
 * sind bezahlte Zugänge). Der Verwalter muss aktiver Teilnehmer der Schule
 * sein. Läuft auf der Owner-Verbindung.
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
            $membership = $owner ? TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $owner->id)
                ->where('role', 'learner')->where('status', 'active')->first() : null;
            if (! $membership) {
                throw new RuntimeException('Der Verwalter muss aktiver Teilnehmer dieser Schule sein.');
            }
            $seats = filter_var($this->option('seats'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($seats === false) {
                throw new RuntimeException('--seats muss eine Zahl zwischen 1 und 10000 sein.');
            }
            $name = trim((string) $this->argument('name'));
            if ($name === '' || mb_strlen($name) > 120) {
                throw new RuntimeException('Der Name muss 1 bis 120 Zeichen lang sein.');
            }
            $scopes = collect($this->option('course'))->map(function (string $value) {
                [$code, $feature] = array_pad(explode(':', trim($value), 2), 2, null);

                return array_filter(['course_code' => strtoupper((string) $code), 'feature' => $feature ?: 'course']);
            })->values()->all();
            try {
                $groups->validateScopes($scopes);
            } catch (RuntimeException $e) {
                throw new RuntimeException('Kurs oder Funktion nicht unterstützt ('.$e->getMessage().').');
            }
            $validUntil = $this->option('valid-until') ? Carbon::parse((string) $this->option('valid-until'))->endOfDay() : null;
            if ($validUntil && $validUntil->isPast()) {
                throw new RuntimeException('--valid-until liegt in der Vergangenheit.');
            }

            $group = LearningGroup::create([
                'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => $name, 'status' => 'active',
                'seats' => $seats, 'scopes' => $scopes, 'valid_from' => now(), 'valid_until' => $validUntil,
                'join_code' => LearningGroupService::newJoinCode(), 'source_type' => 'manual',
                'source_reference' => $this->option('source-reference') ?: null,
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info("Lerngruppe {$group->id} angelegt ({$seats} Plätze).");

        return self::SUCCESS;
    }
}
