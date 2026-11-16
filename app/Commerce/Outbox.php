<?php

namespace App\Commerce;

use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BestEffortMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ausgang App -> Shop (Outbox): legt der Superadmin eine Schule an, wird
 * im Shop ein passendes Kundenkonto angelegt bzw. aktualisiert (das Plugin
 * schickt den Passwort-Einrichtungslink selbst; Passwörter werden nie
 * übertragen). Drei Versuche mit wachsendem Abstand, danach „fehlgeschlagen“
 * mit Hinweis im Superadmin-Bereich und Alarm-Mail.
 */
class Outbox
{
    public const PROVISION = 'tenant.provision';

    public static function enabled(): bool
    {
        return (bool) config('commerce.shop_url') && (bool) config('commerce.outbound_secret');
    }

    /** Shopkonto für eine neu angelegte Schule vormerken (Owner-Verbindung). */
    public static function provisionTenant(Tenant $tenant, User $owner): ?CommerceShopAccount
    {
        if (! self::enabled()) {
            return null;
        }

        return DB::connection('pgsql_admin')->transaction(function () use ($tenant, $owner) {
            $account = CommerceShopAccount::on('pgsql_admin')->create([
                'shop_id' => (string) config('commerce.shop_id'),
                'shop_customer_id' => null,
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
                'status' => CommerceShopAccount::LINKED,
                'email' => strtolower($owner->email),
                'contact_name' => trim($owner->first_name.' '.$owner->last_name) ?: $owner->display_name,
                'company_name' => $tenant->name,
                'provisioning_status' => 'pending',
                'linked_at' => now(),
            ]);
            CommerceEvent::on('pgsql_admin')->create([
                'direction' => 'out',
                'type' => self::PROVISION,
                'shop_account_id' => $account->id,
                'tenant_id' => $tenant->id,
                'correlation_id' => $account->id,
                'payload' => [
                    'account_id' => $account->id,
                    'app_tenant_id' => $tenant->id,
                    'app_user_id' => $owner->id,
                    'email' => $account->email,
                    'first_name' => $owner->first_name,
                    'last_name' => $owner->last_name,
                    'company_name' => $tenant->name,
                ],
                'status' => 'pending',
                'available_at' => now(),
                'created_at' => now(),
            ]);

            return $account;
        });
    }

    /** Nach so vielen Minuten in „sending“ gilt ein Versand als abgebrochen (Prozess beendet, Absturz). */
    public const STUCK_MINUTES = 10;

    /** Fällige Ausgangs-Ereignisse senden. @return int Anzahl versuchter Ereignisse */
    public function process(): int
    {
        $this->recoverStuck();
        $ids = CommerceEvent::on('pgsql_admin')->where('direction', 'out')->where('status', 'pending')
            ->where('available_at', '<=', now())->orderBy('created_at')->limit(50)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += $this->processOne($id) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Ein Ereignis exklusiv beanspruchen und senden: Scheduler, defer() nach dem
     * Anlegen einer Schule und „Erneut versuchen“ können gleichzeitig laufen --
     * gesendet wird trotzdem nur einmal. Beansprucht wird mit einem einzelnen
     * bedingten UPDATE (pending -> sending), das sofort committet; der
     * HTTP-Aufruf läuft danach ohne offene Transaktion und ohne Zeilensperre.
     * Ein Datenbankfehler betrifft nur dieses Ereignis, nicht die übrigen.
     */
    public function processOne(string $id): bool
    {
        try {
            if (! $this->claim($id)) {
                return false;
            }
            $event = CommerceEvent::on('pgsql_admin')->find($id);
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

    /** pending -> sending, nur wenn fällig und noch von niemandem beansprucht. */
    public function claim(string $id): bool
    {
        return DB::connection('pgsql_admin')->table('commerce_event')
            ->where('id', $id)->where('direction', 'out')->where('status', 'pending')->where('available_at', '<=', now())
            ->update(['status' => 'sending', 'claimed_at' => now()]) === 1;
    }

    /**
     * Ereignisse, die zu lange in „sending“ stehen (der sendende Prozess wurde
     * beendet, bevor er das Ergebnis schreiben konnte), zählen als
     * fehlgeschlagener Versuch: wieder „pending“ mit Wartezeit bzw. nach dem
     * letzten Versuch „fehlgeschlagen“ mit Alarm. Ob die Anfrage den Shop noch
     * erreicht hat, ist unklar -- die Anlage im Shop ist idempotent.
     *
     * @return int Anzahl zurückgeholter Ereignisse
     */
    public function recoverStuck(): int
    {
        $cutoff = now()->subMinutes(self::STUCK_MINUTES);
        $stuck = CommerceEvent::on('pgsql_admin')->where('direction', 'out')->where('status', 'sending')
            ->where('claimed_at', '<', $cutoff)->limit(50)->get();
        $recovered = 0;
        foreach ($stuck as $event) {
            // Bedingt zurücksetzen: hat der Sender inzwischen doch fertig
            // geschrieben, bleibt sein Ergebnis stehen. Ohne available_at ist es
            // bis zum Schreiben des Fehlversuchs für niemanden beanspruchbar.
            $reset = DB::connection('pgsql_admin')->table('commerce_event')->where('id', $event->id)
                ->where('status', 'sending')->where('claimed_at', '<', $cutoff)
                ->update(['status' => 'pending', 'claimed_at' => null, 'available_at' => null]);
            if ($reset !== 1) {
                continue;
            }
            $event->forceFill(['status' => 'pending', 'claimed_at' => null]);
            $account = CommerceShopAccount::on('pgsql_admin')->find($event->shop_account_id);
            $this->failed($event, $account, new \RuntimeException('Keine Rückmeldung des Versands nach '.self::STUCK_MINUTES.' Minuten (Prozess abgebrochen).'), false);
            $recovered++;
        }

        return $recovered;
    }

    /** Ein beanspruchtes Ereignis (status „sending“) senden und das Ergebnis schreiben. */
    public function send(CommerceEvent $event): void
    {
        $account = CommerceShopAccount::on('pgsql_admin')->find($event->shop_account_id);
        try {
            if (! self::enabled()) {
                throw new \RuntimeException('Shop-Anbindung nicht konfiguriert (COMMERCE_SHOP_URL / COMMERCE_OUTBOUND_SECRET).');
            }
            $path = '/wp-json/boatingschool/v1/provision';
            $body = json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = Http::timeout(15)->acceptJson()
                ->withHeaders(Signature::headers((string) config('commerce.outbound_key_id'), (string) config('commerce.outbound_secret'), 'POST', $path, $body)
                    + ['Content-Type' => 'application/json', 'Idempotency-Key' => $event->id])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('commerce.shop_url'), '/').$path);
            $customerId = $response->json('customer_id');
            if (! $response->successful() || ! $customerId) {
                throw new \RuntimeException('Shop antwortete '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
            }
            if ($response->json('matched_by') === 'email') {
                // Im Shop gibt es schon ein fremdes Konto mit dieser Adresse:
                // E-Mail-Gleichheit verknüpft nie -- die Person verknüpft es
                // selbst mit einem Verknüpfungscode aus der App.
                DB::connection('pgsql_admin')->transaction(function () use ($event, $account) {
                    if (! $this->stillClaimed($event)) {
                        return;
                    }
                    $account?->forceFill(['provisioning_status' => 'needs_link', 'provisioning_error' => null])->save();
                    $this->markDone($event);
                });

                return;
            }

            DB::connection('pgsql_admin')->transaction(function () use ($event, $account, $customerId) {
                if (! $this->stillClaimed($event)) {
                    return;
                }
                if ($account) {
                    // Gab es das Shopkonto schon (z. B. selbst registriert), gilt die
                    // bestehende Zeile -- die vorgemerkte wird übernommen.
                    $existing = CommerceShopAccount::on('pgsql_admin')->where('shop_id', $account->shop_id)
                        ->where('shop_customer_id', (string) $customerId)->where('id', '!=', $account->id)->first();
                    $existingElsewhere = $existing && $existing->tenant_id && $existing->tenant_id !== $account->tenant_id
                        && $existing->status === CommerceShopAccount::LINKED && $existing->canPurchase();
                    if ($existingElsewhere) {
                        // Ein Kundenkonto kauft für genau eine Schule: die bestehende
                        // Verknüpfung nicht still auf die neue Schule umhängen.
                        $account->forceFill(['provisioning_status' => 'needs_link',
                            'provisioning_error' => 'Das Kundenkonto im Shop kauft bereits für eine andere Bootsschule ein.'])->save();
                    } elseif ($existing) {
                        $existing->forceFill([
                            'user_id' => $account->user_id, 'tenant_id' => $account->tenant_id, 'status' => CommerceShopAccount::LINKED,
                            'linked_at' => now(), 'provisioning_status' => 'done', 'provisioning_error' => null,
                        ])->save();
                        $event->forceFill(['shop_account_id' => $existing->id])->save();
                        $account->delete();
                    } else {
                        $account->forceFill(['shop_customer_id' => (string) $customerId, 'provisioning_status' => 'done', 'provisioning_error' => null])->save();
                    }
                }
                $this->markDone($event);
            });
        } catch (Throwable $e) {
            $this->failed($event, $account, $e);
        }
    }

    /**
     * Gehört das Ereignis noch diesem Versand? (Sperrt die Zeile bis zum Ende
     * der Ergebnis-Transaktion.) Hat die Rückholung es inzwischen neu
     * eingereiht, schreibt dieser Versand nichts mehr -- der nächste Versuch
     * ist idempotent.
     */
    private function stillClaimed(CommerceEvent $event): bool
    {
        return CommerceEvent::on('pgsql_admin')->whereKey($event->id)->where('status', 'sending')->lockForUpdate()->exists();
    }

    private function markDone(CommerceEvent $event): void
    {
        $event->forceFill(['status' => 'done', 'attempts' => $event->attempts + 1, 'processed_at' => now(), 'last_error' => null,
            'claimed_at' => null, 'payload' => self::withoutPersonalData($event->payload)])->save();
    }

    /**
     * Fehlversuch schreiben. $claimed: nur, wenn das Ereignis noch diesem
     * Versand gehört (die Rückholung hängender Versände ruft ohne auf).
     */
    private function failed(CommerceEvent $event, ?CommerceShopAccount $account, Throwable $e, bool $claimed = true): void
    {
        $backoff = (array) config('commerce.outbox_backoff_minutes', [1, 5, 30]);
        $attempts = $event->attempts + 1;
        // Erster Versuch sofort, danach je ein weiterer nach jeder Wartezeit.
        $final = $attempts > count($backoff);
        $written = DB::connection('pgsql_admin')->transaction(function () use ($event, $account, $e, $claimed, $attempts, $final, $backoff) {
            if ($claimed && ! $this->stillClaimed($event)) {
                return false;
            }
            $event->forceFill([
                'attempts' => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'status' => $final ? 'failed' : 'pending',
                'available_at' => $final ? null : now()->addMinutes((int) $backoff[$attempts - 1]),
                'claimed_at' => null,
            ])->save();
            $account?->forceFill(['provisioning_status' => $final ? 'failed' : 'pending', 'provisioning_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return true;
        });
        if (! $written) {
            return;
        }

        if ($final) {
            report($e);
            $to = config('commerce.alert_email') ?: PlatformSetting::on('pgsql_admin')->value('support_email');
            if ($to) {
                $tenantName = $event->tenant_id ? Tenant::on('pgsql_admin')->whereKey($event->tenant_id)->value('name') : '—';
                BestEffortMail::send(fn () => Mail::raw(
                    "Die Anlage des Shopkontos für die Bootsschule „{$tenantName}“ ist nach {$attempts} Versuchen fehlgeschlagen.\n\n"
                    ."Fehler: {$event->last_error}\n\nBitte im Superadmin-Bereich unter „Shopkonten“ prüfen und erneut anstoßen.",
                    fn ($m) => $m->to($to)->subject('Shop-Anbindung: Shopkonto konnte nicht angelegt werden')
                ), 'Commerce-Alarm '.$event->id);
            }
        }
    }

    /** Erledigt: Adresse und Name werden nicht mehr gebraucht (Datensparsamkeit). */
    private static function withoutPersonalData(?array $payload): array
    {
        return array_diff_key((array) $payload, array_flip(['email', 'first_name', 'last_name']));
    }

    /** Fehlgeschlagenes Ereignis erneut einreihen (Superadmin). */
    public static function retry(CommerceEvent $event): void
    {
        $event->forceFill(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null, 'claimed_at' => null])->save();
        CommerceShopAccount::on('pgsql_admin')->whereKey($event->shop_account_id)->update(['provisioning_status' => 'pending']);
    }
}
