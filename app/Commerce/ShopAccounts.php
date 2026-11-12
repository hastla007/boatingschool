<?php

namespace App\Commerce;

use App\Api\ApiProblem;
use App\Models\CommerceEvent;
use App\Models\CommerceLinkCode;
use App\Models\CommerceShopAccount;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Shopkonten (Shop -> App): Registrierung, Verknüpfung per Einmalcode,
 * Status und Stammdaten. Läuft auf der Owner-Verbindung (Server-zu-Server,
 * kein Schulkontext). E-Mail-Gleichheit allein verknüpft nie.
 */
class ShopAccounts
{
    /**
     * Registrierung im Shop: neues App-Konto (ausstehend, B2B-Prüfung) --
     * oder, wenn die Adresse schon ein App-Konto hat, Warten auf den
     * Verknüpfungscode dieses Kontos.
     *
     * @param  array{shop_customer_id: string, email: string, first_name?: ?string, last_name?: ?string, company_name?: ?string, billing_country?: ?string, vat_id?: ?string}  $data
     */
    public function register(string $shopId, array $data): CommerceShopAccount
    {
        $email = strtolower(trim($data['email']));

        return DB::transaction(function () use ($shopId, $data, $email) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['commerce-account:'.$shopId.':'.$data['shop_customer_id']]);
            $account = CommerceShopAccount::where('shop_id', $shopId)->where('shop_customer_id', $data['shop_customer_id'])->first();
            if ($account) {
                // Wiederholung (Retry des Shops): nur die Shop-Stammdaten nachziehen.
                $this->updateShopData($account, $data);

                return $account;
            }

            $existing = User::whereRaw('lower(email) = ?', [$email])->first();
            $user = null;
            if (! $existing) {
                $user = User::create([
                    'display_name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: $email,
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    // Wie eine Einladung: ein Passwort setzt die Person erst
                    // nach der Freigabe über den Einrichtungslink.
                    'status' => 'invited',
                ]);
            }

            $account = CommerceShopAccount::create([
                'shop_id' => $shopId,
                'shop_customer_id' => $data['shop_customer_id'],
                'user_id' => $user?->id,
                'status' => $user ? CommerceShopAccount::PENDING_REVIEW : CommerceShopAccount::AWAITING_LINK,
                'email' => $email,
                'contact_name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: null,
                'company_name' => $data['company_name'] ?? null,
                'billing_country' => isset($data['billing_country']) ? strtoupper($data['billing_country']) : null,
                'vat_id' => $data['vat_id'] ?? null,
                'shop_data_updated_at' => now(),
            ]);
            CommerceEvent::logIncoming('account.registered', $account, null, ['status' => $account->status, 'new_app_user' => (bool) $user]);

            return $account;
        });
    }

    /** Verknüpfung mit dem Code, den ein Schuladmin in der App erzeugt hat. */
    public function confirmLink(CommerceShopAccount $account, string $code): CommerceShopAccount
    {
        $limiterKey = 'commerce-link:'.$account->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Versuche', 'Bitte in einigen Minuten erneut versuchen.', [], ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)]);
        }
        if ($account->status === CommerceShopAccount::REJECTED) {
            throw new ApiProblem(409, 'ACCOUNT_REJECTED', 'Konto abgelehnt', 'Dieses Shopkonto wurde in der Prüfung abgelehnt.');
        }

        return DB::transaction(function () use ($account, $code, $limiterKey) {
            $linkCode = CommerceLinkCode::withoutGlobalScopes()->where('code_hash', CommerceLinkCode::hash($code))->lockForUpdate()->first();
            $creatorStillAdmin = $linkCode && TenantUser::withoutGlobalScopes()->where('tenant_id', $linkCode->tenant_id)->where('user_id', $linkCode->created_by_user_id)
                ->whereIn('role', ['owner', 'admin'])->where('status', 'active')->exists();
            $tenantActive = $linkCode && in_array(Tenant::find($linkCode->tenant_id)?->status, ['active', 'trial'], true);

            if (! $linkCode || $linkCode->used_at || $linkCode->expires_at->isPast() || ! $creatorStillAdmin || ! $tenantActive) {
                RateLimiter::hit($limiterKey, 600);
                // Einheitlich, ohne zu verraten, warum.
                throw new ApiProblem(422, 'LINK_CODE_INVALID', 'Code ungültig', 'Der Verknüpfungscode ist ungültig oder abgelaufen. Bitte in der App einen neuen erzeugen.');
            }

            $linkCode->forceFill(['used_at' => now(), 'used_by_account_id' => $account->id])->save();
            $account->forceFill([
                'status' => CommerceShopAccount::LINKED,
                'tenant_id' => $linkCode->tenant_id,
                // Wer den Code erzeugt hat, hat sich in der App angemeldet:
                // diese Identität gilt für das Shopkonto.
                'user_id' => $linkCode->created_by_user_id,
                'linked_at' => now(),
                'revoked_at' => null,
            ])->save();
            RateLimiter::clear($limiterKey);
            // Platzhalter aus der Anlage einer Schule, für die der Shop ein
            // fremdes Konto gleicher Adresse meldete (needs_link): jetzt erledigt.
            CommerceShopAccount::where('tenant_id', $account->tenant_id)->whereNull('shop_customer_id')
                ->where('provisioning_status', 'needs_link')->where('id', '!=', $account->id)->delete();
            CommerceEvent::logIncoming('account.linked', $account, null, ['tenant_id' => $account->tenant_id]);

            return $account;
        });
    }

    /** Stammdaten aus dem Shop (dort führend) -- ändert nie das App-Konto selbst. */
    public function updateShopData(CommerceShopAccount $account, array $data): CommerceShopAccount
    {
        $fields = array_filter([
            'email' => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'contact_name' => array_key_exists('first_name', $data) || array_key_exists('last_name', $data)
                ? (trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: null) : null,
            'company_name' => $data['company_name'] ?? null,
            'billing_country' => isset($data['billing_country']) ? strtoupper($data['billing_country']) : null,
            'vat_id' => $data['vat_id'] ?? null,
        ], fn ($v) => $v !== null);
        if ($fields !== []) {
            $account->forceFill($fields + ['shop_data_updated_at' => now()])->save();
        }

        return $account;
    }

    /** Shopkunde gelöscht oder deaktiviert: keine Einkäufe mehr, Historie bleibt. */
    public function deactivate(CommerceShopAccount $account): CommerceShopAccount
    {
        if ($account->status !== CommerceShopAccount::REJECTED) {
            $account->forceFill(['status' => CommerceShopAccount::REVOKED, 'revoked_at' => now()])->save();
        }
        CommerceEvent::logIncoming('account.deactivated', $account, null, []);

        return $account;
    }

    public function find(string $shopId, string $shopCustomerId): CommerceShopAccount
    {
        $account = CommerceShopAccount::where('shop_id', $shopId)->where('shop_customer_id', $shopCustomerId)->first();
        if (! $account) {
            throw ApiProblem::notFound('Shopkonto');
        }

        return $account;
    }

    public function present(CommerceShopAccount $account): array
    {
        $tenant = $account->tenant_id ? Tenant::find($account->tenant_id) : null;

        return [
            'account_id' => $account->id,
            'shop_customer_id' => $account->shop_customer_id,
            // Nach außen nie verraten, ob eine Adresse schon ein App-Konto hat:
            // „wartet auf Verknüpfung“ erscheint im Shop wie „in Prüfung“.
            'status' => $account->status === CommerceShopAccount::AWAITING_LINK ? CommerceShopAccount::PENDING_REVIEW : $account->status,
            'can_purchase' => $account->canPurchase(),
            // Nur bei bestehender Verknüpfung -- sonst verriete null/gesetzt, ob die
            // Adresse schon ein App-Konto hat.
            'app_user_id' => $account->status === CommerceShopAccount::LINKED ? $account->user_id : null,
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
            'linked_at' => $account->linked_at?->toIso8601ZuluString(),
        ];
    }
}
