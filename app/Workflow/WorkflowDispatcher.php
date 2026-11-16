<?php

namespace App\Workflow;

use App\Models\WorkflowEvent;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Zustellung der Ausgangstabelle workflow_event an den BoatingSchool Workflow
 * Connector (WF-CUST1-02). Mindestens einmal: ein Ereignis wird zugestellt,
 * bis der Connector es annimmt (oder als Doppel erkennt) oder alle Versuche
 * verbraucht sind ("failed", sichtbar und per Befehl erneut einreihbar).
 *
 * Wie App\Commerce\Outbox: beansprucht wird mit einem bedingten UPDATE
 * (pending -> sending), der HTTP-Aufruf läuft ohne offene Transaktion, das
 * Ergebnis wird nur geschrieben, solange das Ereignis noch diesem Versand
 * gehört. Hängende Versände (Prozess beendet) zählen als Fehlversuch.
 *
 * Zeitüberschreitung nach erfolgreichem Aufruf: das Ereignis wird mit
 * derselben Event-ID erneut gesendet; der Connector erkennt sie und
 * antwortet "duplicate" -- das gilt als zugestellt.
 *
 * In last_error steht nur ein Code (http_503, timeout, rejected:…), nie ein
 * Antworttext.
 */
class WorkflowDispatcher
{
    public const STUCK_MINUTES = 10;

    public const BATCH = 50;

    /** Antworten, nach denen ein weiterer Versuch sinnlos ist (Vertrag oder Inhalt falsch). */
    private const PERMANENT = [400, 404, 410, 413, 415, 422];

    public static function configured(): bool
    {
        return (bool) config('workflow.endpoint') && (string) config('workflow.outbound_secret') !== '';
    }

    private function db(): Connection
    {
        return DB::connection('pgsql_admin');
    }

    /** @return int Anzahl versuchter Ereignisse */
    public function process(): int
    {
        if (! self::configured()) {
            return 0;
        }
        $this->recoverStuck();
        $ids = $this->db()->table('workflow_event')->where('status', 'pending')->where('available_at', '<=', now())
            ->orderBy('created_at')->limit(self::BATCH)->pluck('id');
        $tried = 0;
        foreach ($ids as $id) {
            $tried += $this->processOne($id) ? 1 : 0;
        }

        return $tried;
    }

    public function processOne(string $id): bool
    {
        try {
            if (! self::configured() || ! $this->claim($id)) {
                return false;
            }
            $event = WorkflowEvent::on('pgsql_admin')->find($id);
            if (! $event) {
                return false;
            }
            $this->send($event);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function claim(string $id): bool
    {
        return $this->db()->table('workflow_event')->where('id', $id)->where('status', 'pending')
            ->where('available_at', '<=', now())->update(['status' => 'sending', 'claimed_at' => now()]) === 1;
    }

    public function recoverStuck(): int
    {
        $cutoff = now()->subMinutes(self::STUCK_MINUTES);
        $stuck = WorkflowEvent::on('pgsql_admin')->where('status', 'sending')->where('claimed_at', '<', $cutoff)->limit(self::BATCH)->get();
        $recovered = 0;
        foreach ($stuck as $event) {
            $reset = $this->db()->table('workflow_event')->where('id', $event->id)->where('status', 'sending')
                ->where('claimed_at', '<', $cutoff)->update(['status' => 'pending', 'claimed_at' => null, 'available_at' => null]);
            if ($reset === 1) {
                $event->forceFill(['status' => 'pending', 'claimed_at' => null]);
                $this->failed($event, 'stuck', false, false);
                $recovered++;
            }
        }

        return $recovered;
    }

    /** Ein beanspruchtes Ereignis senden und das Ergebnis schreiben. */
    public function send(WorkflowEvent $event): void
    {
        $body = json_encode([
            'op' => 'event',
            'contract_version' => (string) config('workflow.contract_version'),
            'event' => $event->envelope(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $response = Http::timeout((int) config('workflow.timeout_seconds', 10))->acceptJson()
                ->withHeaders(WorkflowSignature::headers((string) config('workflow.outbound_key_id'), (string) config('workflow.outbound_secret'), $body)
                    + ['Content-Type' => 'application/json', 'Idempotency-Key' => $event->id])
                ->withBody($body, 'application/json')
                ->post((string) config('workflow.endpoint'));
        } catch (ConnectionException $e) {
            $this->failed($event, 'timeout', true);

            return;
        }

        $result = (string) $response->json('result');
        if ($response->successful() && in_array($result, ['captured', 'duplicate', 'skipped'], true)) {
            $this->done($event);

            return;
        }
        if ($response->status() === 409 && $result === 'duplicate') {
            $this->done($event);

            return;
        }
        $code = preg_replace('/[^a-z0-9_:.-]/', '', strtolower((string) $response->json('code')));
        $permanent = in_array($response->status(), self::PERMANENT, true);
        $this->failed($event, ($permanent ? 'rejected' : 'http_'.$response->status()).($code !== '' ? ':'.mb_substr($code, 0, 60) : ''), true, $permanent);
    }

    private function stillClaimed(WorkflowEvent $event): bool
    {
        return WorkflowEvent::on('pgsql_admin')->whereKey($event->id)->where('status', 'sending')->lockForUpdate()->exists();
    }

    private function done(WorkflowEvent $event): void
    {
        $this->db()->transaction(function () use ($event) {
            if (! $this->stillClaimed($event)) {
                return;
            }
            $event->forceFill(['status' => 'done', 'attempts' => $event->attempts + 1, 'delivered_at' => now(), 'last_error' => null, 'claimed_at' => null])->save();
        });
    }

    private function failed(WorkflowEvent $event, string $error, bool $claimed, bool $permanent = false): void
    {
        $backoff = (array) config('workflow.backoff_minutes', [1, 5, 15, 60, 180, 360, 720, 1440]);
        $attempts = $event->attempts + 1;
        $final = $permanent || $attempts > count($backoff);
        $written = $this->db()->transaction(function () use ($event, $error, $claimed, $attempts, $final, $backoff) {
            if ($claimed && ! $this->stillClaimed($event)) {
                return false;
            }
            $event->forceFill([
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 120),
                'status' => $final ? 'failed' : 'pending',
                'available_at' => $final ? null : now()->addMinutes((int) $backoff[$attempts - 1]),
                'claimed_at' => null,
            ])->save();

            return true;
        });
        if ($written && $final) {
            report(new \RuntimeException("Workflow-Ereignis {$event->id} ({$event->type}) nicht zugestellt: {$error}"));
        }
    }

    /** Fehlgeschlagenes Ereignis erneut einreihen (Outbox-Replay): dieselbe Event-ID, der Connector erkennt Doppel. */
    public static function requeue(string $id): bool
    {
        return DB::connection('pgsql_admin')->table('workflow_event')->where('id', $id)->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null, 'claimed_at' => null]) === 1;
    }

    /** Erledigte Ereignisse nach der Frist und abgelaufene Nonces löschen. @return int gelöschte Ereignisse */
    public static function prune(): int
    {
        DB::connection('pgsql_admin')->table('workflow_nonce')->where('expires_at', '<', now())->delete();

        return DB::connection('pgsql_admin')->table('workflow_event')->where('status', 'done')
            ->where('delivered_at', '<', now()->subDays((int) config('workflow.retention_days', 30)))->delete();
    }
}
