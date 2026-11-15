<?php

namespace App\Support;

use App\Models\ContentAnswer;

/**
 * Sicherheitsfund SEC-A1: Antwort-IDs sind UUIDv7 (zeitlich aufsteigend) und
 * wurden in der Reihenfolge A, B, C, D angelegt -- bei den amtlichen
 * Katalogen ist A die richtige Antwort, die kleinste ID verriet also die
 * Lösung. Web und App bekommen deshalb nie die echte ID, sondern eine
 * Kennung je Person: HMAC-SHA256 (APP_KEY) über „userId|answerId“, als
 * UUID (Version 4) formatiert, damit bestehende Validierungen („uuid“) und
 * Clients unverändert bleiben. Stabil für die Person, für andere wertlos.
 */
class AnswerToken
{
    /** Nie eine Antwort-ID: steht beim Auflösen für „unbekannte Kennung“. */
    public const UNKNOWN = '00000000-0000-0000-0000-000000000000';

    public static function for(string $userId, string $answerId): string
    {
        $bytes = substr(hash_hmac('sha256', $userId.'|'.$answerId, (string) config('app.key'), true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // Version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // Variante RFC 4122
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /**
     * Kennung gegen die Antworten der betroffenen Revision auflösen
     * (Vergleich in konstanter Zeit); null bei unbekannter Kennung.
     *
     * @param  iterable<ContentAnswer|string>  $answers  Antworten bzw. ihre IDs
     */
    public static function resolve(string $userId, string $token, iterable $answers): ?string
    {
        $token = strtolower(trim($token));
        $found = null;
        foreach ($answers as $answer) {
            $id = $answer instanceof ContentAnswer ? $answer->id : (string) $answer;
            if (hash_equals(self::for($userId, $id), $token)) {
                $found = $id;
            }
        }

        return $found;
    }

    /**
     * Mehrere Kennungen auflösen; unbekannte werden zu UNKNOWN (passt zu
     * keiner Antwort, die Dienste melden dann wie bisher „unbekannte
     * Antwort“).
     *
     * @param  iterable<ContentAnswer|string>  $answers
     * @return list<string>
     */
    public static function resolveMany(string $userId, iterable $tokens, iterable $answers): array
    {
        $answers = is_array($answers) ? $answers : iterator_to_array($answers, false);
        $resolved = [];
        foreach ($tokens as $token) {
            $resolved[] = self::resolve($userId, (string) $token, $answers) ?? self::UNKNOWN;
        }

        return $resolved;
    }

    /**
     * Echte IDs (z. B. gespeicherte Auswahl) in Kennungen der Person umsetzen.
     *
     * @param  iterable<string|null>  $answerIds
     * @return list<string>
     */
    public static function mapMany(string $userId, iterable $answerIds): array
    {
        $tokens = [];
        foreach ($answerIds as $id) {
            if ($id !== null && $id !== '') {
                $tokens[] = self::for($userId, (string) $id);
            }
        }

        return $tokens;
    }

    /**
     * Formulareingaben answer_id / answer_ids (Kennungen) in echte IDs der
     * Revision umsetzen, bevor sie an LearningFlow, ExamActions oder
     * LearningService gehen.
     *
     * @param  iterable<ContentAnswer|string>  $answers
     */
    public static function resolveInput(string $userId, array $input, iterable $answers): array
    {
        $answers = is_array($answers) ? $answers : iterator_to_array($answers, false);
        if (! empty($input['answer_id'])) {
            $input['answer_id'] = self::resolve($userId, (string) $input['answer_id'], $answers) ?? self::UNKNOWN;
        }
        if (! empty($input['answer_ids']) && is_array($input['answer_ids'])) {
            $input['answer_ids'] = self::resolveMany($userId, $input['answer_ids'], $answers);
        }

        return $input;
    }
}
