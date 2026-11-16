<?php

namespace App\Api;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tagesgrenze für ausgelieferte Fragen in der Mobile-API (Schutz gegen das
 * Abgreifen des Fragenkatalogs mit einem einzelnen Konto): je Konto und
 * Kalendertag (Europe/Berlin) höchstens mobile_api.daily_question_limit
 * Fragen aus Lernmodus (trainer/next, je Frage 1) und neu gestarteten
 * Prüfungen (je Frage der Prüfung). 0 schaltet die Grenze ab. Über der
 * Grenze: 429 DAILY_LIMIT_REACHED mit Retry-After bis Mitternacht.
 */
class DailyQuestionLimit
{
    public static function limit(): int
    {
        return max(0, (int) config('mobile_api.daily_question_limit', 2000));
    }

    public static function key(User $user): string
    {
        return 'api-daily-questions:'.$user->id.':'.Carbon::now('Europe/Berlin')->format('Y-m-d');
    }

    /** Wirft 429, wenn das Tageskontingent schon aufgebraucht ist. */
    public static function ensureAvailable(User $user): void
    {
        $limit = self::limit();
        if ($limit === 0 || RateLimiter::attempts(self::key($user)) < $limit) {
            return;
        }

        throw new ApiProblem(
            429,
            'DAILY_LIMIT_REACHED',
            'Tageslimit erreicht',
            "Du hast heute schon {$limit} Fragen in der App geübt – das ist das Tageslimit. Ab Mitternacht geht es weiter.",
            [],
            ['Retry-After' => (string) self::secondsUntilMidnight()],
        );
    }

    /** Ausgelieferte Fragen zählen. */
    public static function consume(User $user, int $questions = 1): void
    {
        if (self::limit() === 0 || $questions < 1) {
            return;
        }

        // Etwas über Mitternacht hinaus halten; der Schlüssel enthält ohnehin das Datum.
        RateLimiter::increment(self::key($user), self::secondsUntilMidnight() + 60, $questions);
    }

    private static function secondsUntilMidnight(): int
    {
        $now = Carbon::now('Europe/Berlin');

        return max(1, (int) ceil($now->diffInSeconds($now->copy()->addDay()->startOfDay(), true)));
    }
}
