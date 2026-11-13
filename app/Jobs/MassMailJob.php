<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gemeinsame Grundlage der Sammel-Mails (Mitteilungen, Lernerinnerungen,
 * Sammel-Einladungen): ein Job je Empfänger-Paket (CHUNK), läuft im
 * Queue-Worker statt im Request.
 *
 * Wiederholung: Schlägt eine Mail fehl, laufen die übrigen weiter; am Ende
 * wirft der Job, damit der Worker ihn nach $backoff erneut versucht
 * (höchstens $tries Mal). Bereits zugestellte Mails werden dabei nicht noch
 * einmal verschickt (Merker je Lauf und Empfänger im Cache, siehe
 * sendOnce()). Nach dem letzten Versuch: failed() protokolliert und zählt
 * (app:check-production zeigt den Zähler).
 */
abstract class MassMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAware;

    /** Empfänger je Job. */
    public const CHUNK = 50;

    /** Zähler fehlgeschlagener Sammel-Mail-Jobs (für app:check-production). */
    public const FAILED_COUNTER_KEY = 'ops:mass-mail-jobs-failed';

    public int $tries = 3;

    /** @var list<int> Sekunden bis zum 2. bzw. 3. Versuch */
    public array $backoff = [60, 300, 900];

    /** Kennung dieses Pakets, bleibt über Wiederholungen gleich (Merker in sendOnce()). */
    public string $runId;

    protected function initializeMassMail(?string $tenantId = null): void
    {
        $this->rememberTenant($tenantId);
        $this->runId = (string) Str::uuid();
    }

    /**
     * Eine Mail höchstens einmal je Paket und Empfänger verschicken.
     *
     * @return bool|null true = verschickt, false = fehlgeschlagen, null = schon in einem früheren Versuch verschickt
     */
    protected function sendOnce(string $recipientKey, callable $send, string $context): ?bool
    {
        $key = 'mass-mail:'.$this->runId.':'.$recipientKey;
        if (Cache::has($key)) {
            return null;
        }
        try {
            $send();
        } catch (Throwable $e) {
            report(new RuntimeException("Mailversand fehlgeschlagen: {$context}", 0, $e));

            return false;
        }
        Cache::put($key, true, now()->addDays(2));

        return true;
    }

    /**
     * Fehlgeschlagene Mails: im Worker erneut versuchen lassen (Exception).
     * Mit dem sync-Treiber (Tests, lokal) gibt es keine Wiederholung -- dort
     * bleibt es bei der Meldung im Log, statt den Request abzubrechen.
     */
    protected function retryIfFailed(int $failed): void
    {
        if ($failed > 0 && $this->job !== null && $this->job->getConnectionName() !== 'sync') {
            throw new RuntimeException(static::class.": {$failed} Mail(s) fehlgeschlagen, neuer Versuch folgt.");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Sammel-Mail-Job endgültig fehlgeschlagen', [
            'job' => static::class,
            'tenant_id' => $this->tenantId,
            'run_id' => $this->runId ?? null,
            'error' => $exception?->getMessage(),
        ]);
        Cache::add(self::FAILED_COUNTER_KEY, 0);
        Cache::increment(self::FAILED_COUNTER_KEY);
    }
}
