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

    /** Fällige Ausgangs-Ereignisse senden. @return int Anzahl versuchter Ereignisse */
    public function process(): int
    {
        $ids = CommerceEvent::on('pgsql_admin')->where('direction', 'out')->where('status', 'pending')
            ->where('available_at', '<=', now())->orderBy('created_at')->limit(50)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            // Jedes Ereignis exklusiv beanspruchen: Scheduler, defer() nach dem
            // Anlegen einer Schule und „Erneut versuchen“ können gleichzeitig
            // laufen -- gesendet wird trotzdem nur einmal.
            DB::connection('pgsql_admin')->transaction(function () use ($id, &$sent) {
                $event = CommerceEvent::on('pgsql_admin')->whereKey($id)->where('status', 'pending')
                    ->where('available_at', '<=', now())->lock('for update skip locked')->first();
                if ($event) {
                    $sent++;
                    $this->send($event);
                }
            });
        }

        return $sent;
    }

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
                    $account?->forceFill(['provisioning_status' => 'needs_link', 'provisioning_error' => null])->save();
                    $event->forceFill(['status' => 'done', 'attempts' => $event->attempts + 1, 'processed_at' => now(), 'last_error' => null,
                        'payload' => self::withoutPersonalData($event->payload)])->save();
                });

                return;
            }

            DB::connection('pgsql_admin')->transaction(function () use ($event, $account, $customerId) {
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
                $event->forceFill(['status' => 'done', 'attempts' => $event->attempts + 1, 'processed_at' => now(), 'last_error' => null,
                    'payload' => self::withoutPersonalData($event->payload)])->save();
            });
        } catch (Throwable $e) {
            $this->failed($event, $account, $e);
        }
    }

    private function failed(CommerceEvent $event, ?CommerceShopAccount $account, Throwable $e): void
    {
        $backoff = (array) config('commerce.outbox_backoff_minutes', [1, 5, 30]);
        $attempts = $event->attempts + 1;
        // Erster Versuch sofort, danach je ein weiterer nach jeder Wartezeit.
        $final = $attempts > count($backoff);
        $event->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($e->getMessage(), 0, 1000),
            'status' => $final ? 'failed' : 'pending',
            'available_at' => $final ? null : now()->addMinutes((int) $backoff[$attempts - 1]),
        ])->save();
        $account?->forceFill(['provisioning_status' => $final ? 'failed' : 'pending', 'provisioning_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

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
        $event->forceFill(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null])->save();
        CommerceShopAccount::on('pgsql_admin')->whereKey($event->shop_account_id)->update(['provisioning_status' => 'pending']);
    }
}
