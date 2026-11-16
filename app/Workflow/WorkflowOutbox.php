<?php

namespace App\Workflow;

use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Vormerken von Workflow-Ereignissen (WF-CUST1-02, -03).
 *
 * record() schreibt das Ereignis in die Ausgangstabelle workflow_event auf
 * DERSELBEN Verbindung und in DERSELBEN Transaktion wie die fachliche
 * Änderung: wird sie zurückgerollt, gibt es auch kein Ereignis. Zugestellt
 * wird erst nach dem Commit (afterCommit), sonst spätestens vom Scheduler
 * (app:workflow-outbox, jede Minute). Ein Fehler beim Vormerken bricht den
 * fachlichen Ablauf nie ab (Savepoint, report()).
 *
 * Die Event-ID ist stabil: abgeleitet aus Typ, Version, Gegenstand und
 * Diskriminator. Wiederholt der Shop einen Aufruf, entsteht dasselbe
 * Ereignis nicht zweimal (Primärschlüssel, insertOrIgnore).
 */
final class WorkflowOutbox
{
    public static function enabled(): bool
    {
        return (bool) config('workflow.enabled');
    }

    /** Stabile Event-ID (UUID-Format, Version 5-artig aus SHA-256). */
    public static function eventId(string $type, int $version, string $subjectType, string $subjectId, string $discriminator): string
    {
        $h = hash('sha256', implode('|', ['boatingschool-workflow', $type, $version, $subjectType, $subjectId, $discriminator]));
        $variant = dechex((hexdec($h[16]) & 0x3) | 0x8);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-'.$variant.substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    /**
     * @param  array<string, mixed>  $source  Quelle der Nutzdaten (wird über die Positivliste gelesen).
     * @param  string  $discriminator  Unterscheidet zwei echte Vorgänge desselben Typs am selben Gegenstand;
     *                                 bei einer Wiederholung desselben Vorgangs gleich.
     * @return string|null Event-ID, oder null, wenn nichts vorgemerkt wurde.
     */
    public static function record(string $type, string $subjectId, array $source, string $discriminator, ?string $correlationId = null, ?string $connection = null): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        try {
            $spec = EventCatalog::spec($type);
            $data = PayloadAllowlist::build($type, $source);
            $tenant = $data['tenant_id'] ?? null;
            $id = self::eventId($type, $spec['version'], $spec['subject'], $subjectId, $discriminator);
            $db = DB::connection($connection);
            // Savepoint: scheitert das Vormerken, bleibt die fachliche Transaktion intakt.
            $db->transaction(fn () => $db->table('workflow_event')->insertOrIgnore([
                'id' => $id,
                'type' => $type,
                'version' => $spec['version'],
                'scope' => $tenant ? 'tenant' : 'platform',
                'tenant_ref' => $tenant,
                'subject_type' => $spec['subject'],
                'subject_id' => $subjectId,
                'correlation_id' => mb_substr($correlationId ?? $spec['subject'].':'.$subjectId, 0, 64),
                'payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'attempts' => 0,
                'occurred_at' => now(),
                'available_at' => now(),
                'created_at' => now(),
            ]));
            $db->afterCommit(fn () => self::dispatchSoon($id));

            return $id;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Nach dem Commit zustellen: nach der Antwort (defer), sofort (sync) oder nur über den Scheduler (none). */
    private static function dispatchSoon(string $id): void
    {
        $mode = (string) config('workflow.dispatch', 'defer');
        if ($mode === 'none' || ! WorkflowDispatcher::configured()) {
            return;
        }
        $run = static function () use ($id) {
            try {
                app(WorkflowDispatcher::class)->processOne($id);
            } catch (Throwable $e) {
                report($e);
            }
        };
        $mode === 'sync' ? $run() : defer($run);
    }
}
