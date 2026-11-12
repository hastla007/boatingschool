<?php

/**
 * Entscheidungen der Zustellung, ohne WordPress-Abhängigkeit (testbar):
 * was nach einer Antwort der App mit einer Bestellposition passiert.
 *
 * Grundsatz: ein Timeout oder eine fehlende Antwort löst nie eine zweite
 * Code-Erzeugung aus -- vor jedem Wiederholen wird der Status der Position
 * über die ursprüngliche Referenz abgefragt (die App liefert für dieselbe
 * Bestellposition ohnehin immer denselben Batch).
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Delivery_Policy
{
    const DELIVERED = 'delivered';

    const RETRY = 'retry';

    const FAILED = 'failed';

    /** Wartezeiten bis zum nächsten Versuch (Minuten); danach „Zustellung offen“. */
    const BACKOFF_MINUTES = [1, 5, 30];

    /**
     * @param  int|null  $status  HTTP-Status der App (null = keine Antwort / Netzfehler).
     * @param  int  $attempt  Nummer des gerade beendeten Versuchs (ab 1).
     * @return array{0: string, 1: int|null} Ergebnis und ggf. Minuten bis zum nächsten Versuch.
     */
    public static function decide(?int $status, int $attempt): array
    {
        if ($status !== null && $status >= 200 && $status < 300) {
            return [self::DELIVERED, null];
        }
        // Fachliche Ablehnung (Konto nicht verknüpft, Kurs unbekannt, Konflikt):
        // eine Wiederholung ändert nichts -- sofort an den Support.
        if ($status !== null && $status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            return [self::FAILED, null];
        }
        // Netzfehler, Timeout, 408/429/5xx: später erneut -- nach 1, 5 und 30
        // Minuten, also höchstens vier Versuche (wie der Ausgang der App).
        if ($attempt > count(self::BACKOFF_MINUTES)) {
            return [self::FAILED, null];
        }

        return [self::RETRY, self::BACKOFF_MINUTES[$attempt - 1]];
    }

    /** Eindeutige Referenz einer Bestellposition (Idempotenz in der App). */
    public static function reference(string $shop_id, string $order_id, string $item_id): string
    {
        return $shop_id.':'.$order_id.':'.$item_id;
    }
}
