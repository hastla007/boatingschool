<?php

/**
 * Abgleich zugestellter Positionen mit der Plattform (WP-CLI
 * „wp bs-connector reconcile“). compare() hat keine WordPress-Abhängigkeit.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Reconcile
{
    /**
     * Abweichungen zwischen Shop und Plattform für eine Position.
     *
     * @param  array{status: string, batch_id: string, quantity: int, order_cancelled: bool}  $local  Stand im Shop
     * @param  int|null  $remote_status  HTTP-Status von GET /orders/{id}/lines/{line} (null = keine Antwort)
     * @param  array|null  $remote  data aus der Antwort (batch_id, quantity, status, open)
     * @return list<string> Beschreibungen der Abweichungen (leer = stimmt überein)
     */
    public static function compare(array $local, ?int $remote_status, ?array $remote): array
    {
        $status = (string) ($local['status'] ?? '');
        if ($remote_status === null || $remote_status >= 500 || ($remote_status >= 400 && $remote_status !== 404)) {
            return ['Plattform nicht erreichbar oder Fehler ('.($remote_status ?? 'keine Antwort').')'];
        }
        if ($remote_status === 404) {
            return $status === 'delivered' ? ['Im Shop zugestellt, auf der Plattform nicht gefunden'] : [];
        }

        $problems = [];
        $remote = (array) $remote;
        if ($status === 'failed' || $status === 'pending' || $status === 'deferred') {
            $problems[] = sprintf('Im Shop „%s“, auf der Plattform geliefert', $status);
        }
        $local_batch = (string) ($local['batch_id'] ?? '');
        if ($status === 'delivered' && $local_batch !== '' && $local_batch !== (string) ($remote['batch_id'] ?? '')) {
            $problems[] = 'Abweichende Paket-ID (Shop '.$local_batch.', Plattform '.($remote['batch_id'] ?? '—').')';
        }
        if ((int) ($local['quantity'] ?? 0) !== (int) ($remote['quantity'] ?? 0)) {
            $problems[] = sprintf('Abweichende Menge (Shop %d, Plattform %d)', (int) ($local['quantity'] ?? 0), (int) ($remote['quantity'] ?? 0));
        }
        if (! empty($local['order_cancelled']) && (int) ($remote['open'] ?? 0) > 0) {
            $problems[] = sprintf('Bestellung storniert/erstattet, aber %d Code(s) auf der Plattform noch offen', (int) $remote['open']);
        }

        return $problems;
    }
}
