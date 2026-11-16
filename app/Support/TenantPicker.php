<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Schulauswahl im Superadmin-Bereich (components/superadmin/tenant-picker):
 * statt einer Auswahlliste aller Bootsschulen ein Suchfeld. Mit JavaScript
 * sucht es über Superadmin\TenantSearchController und setzt die gewählte
 * Schul-ID im versteckten Feld (z. B. tenant_id); ohne JavaScript bleibt nur
 * das sichtbare Textfeld (Begleitfeld, z. B. tenant_slug), dessen Kürzel
 * (Slug) oder genauen Namen resolve() serverseitig auflöst.
 *
 * Regeln von resolve():
 *  - Fehlt das Begleitfeld ganz (ältere Links, Formulare oder Skripte, die
 *    nur tenant_id senden), gilt allein die ID -- bisheriges Verhalten.
 *  - Leeres Begleitfeld: keine Schule (bei Pflichtfeldern ein Fehler). So
 *    entfernt Leeren des Felds die Auswahl auch ohne JavaScript, obwohl das
 *    versteckte Feld die bisherige ID noch mitsendet.
 *  - Passt die mitgesendete ID zum Text (Name oder Slug der Schule), gilt
 *    die ID (Auswahl aus der Trefferliste).
 *  - Sonst wird der Text als Slug, ersatzweise als eindeutiger Name gesucht.
 */
final class TenantPicker
{
    /** Höchstzahl der Treffer der Schulsuche. */
    public const SEARCH_LIMIT = 20;

    public const NOT_FOUND = 'Unter dieser Angabe gibt es keine Bootsschule. Bitte einen Treffer aus der Liste wählen oder das Kürzel (Slug) der Schule eingeben.';

    public const REQUIRED = 'Bitte eine Bootsschule wählen.';

    /** Name des sichtbaren Begleitfelds: tenant_id und tenant werden zu tenant_slug. */
    public static function companion(string $name): string
    {
        return preg_replace('/_id$/', '', $name).'_slug';
    }

    /**
     * Validierungsregel für das Begleitfeld.
     *
     * @return list<string>
     */
    public static function companionRules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Gewählte Schul-ID aus den (validierten) Eingaben; wirft bei einer
     * unbekannten Angabe bzw. fehlender Pflichtangabe einen Validierungsfehler
     * am Feld $name.
     *
     * @param  array<string, mixed>  $input
     */
    public static function resolve(array $input, string $name = 'tenant_id', bool $required = false): ?string
    {
        $companion = self::companion($name);
        $id = is_string($input[$name] ?? null) && $input[$name] !== '' ? $input[$name] : null;

        if (! array_key_exists($companion, $input)) {
            if ($required && $id === null) {
                throw ValidationException::withMessages([$name => self::REQUIRED]);
            }

            return $id;
        }

        $text = trim((string) $input[$companion]);
        if ($text === '') {
            if ($required) {
                throw ValidationException::withMessages([$name => self::REQUIRED]);
            }

            return null;
        }

        $resolved = self::find($text, $id);
        if ($resolved === null) {
            throw ValidationException::withMessages([$name => self::NOT_FOUND]);
        }

        return $resolved;
    }

    /**
     * Für GET-Filter (Protokoll, Codes): wie resolve(), aber ohne Ausnahme.
     *
     * @return array{id: ?string, error: ?string}
     */
    public static function resolveQuery(Request $request, string $name = 'tenant_id'): array
    {
        $input = [];
        foreach ([$name, self::companion($name)] as $key) {
            if ($request->query->has($key)) {
                $value = $request->query($key);
                $input[$key] = is_string($value) ? $value : '';
            }
        }
        if (isset($input[$name]) && ! Str::isUuid($input[$name])) {
            $input[$name] = '';
        }

        try {
            return ['id' => self::resolve($input, $name), 'error' => null];
        } catch (ValidationException $exception) {
            return ['id' => null, 'error' => $exception->getMessage()];
        }
    }

    /** Schule zu Text (Slug oder eindeutiger Name, ohne Groß-/Kleinschreibung); $id gewinnt, wenn sie passt. */
    private static function find(string $text, ?string $id): ?string
    {
        $text = mb_strtolower(mb_substr($text, 0, 255));
        $matches = fn ($query) => $query->whereRaw('lower(slug) = ?', [$text])->orWhereRaw('lower(name) = ?', [$text]);

        if ($id !== null && Str::isUuid($id) && Tenant::whereKey($id)->where($matches)->exists()) {
            return $id;
        }

        $bySlug = Tenant::whereRaw('lower(slug) = ?', [$text])->value('id');
        if ($bySlug !== null) {
            return $bySlug;
        }
        $byName = Tenant::whereRaw('lower(name) = ?', [$text])->limit(2)->pluck('id');

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
