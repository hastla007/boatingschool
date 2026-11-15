<?php

namespace App\Services;

use App\Models\ApiDataExport;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Vertragsende einer Bootsschule (Superadmin): alle Daten der Schule als
 * JSON exportieren und die Schule danach endgültig löschen.
 *
 * Die Löschung entfernt die Schule mit allen ihr zugeordneten Daten
 * (Mitgliedschaften, Freischaltungen, Lernstände, Anmeldungen, Bestellungen,
 * Lerngruppen samt Einladungen … per ON DELETE CASCADE), ihr Protokoll, Logo und Favicon. Konten, die
 * danach keiner Bootsschule mehr angehören, werden ebenfalls gelöscht;
 * Konten mit weiteren Mitgliedschaften und Superadmins bleiben. Läuft im
 * Superadmin-Bereich auf der Owner-Verbindung (RLS-frei).
 */
class TenantOffboardingService
{
    public function __construct(private PersonalDataExportService $personalData, private AccountErasureService $erasure) {}

    /** @return array<string, mixed> */
    /**
     * Schreibt den Export Stück für Stück (je Mitglied) über $write --
     * große Schulen sprengten als ein einziges Array Speicher und Zeitlimit
     * (Review-Runde 8: 3.000 Mitglieder ≈ 250 MB). Gibt die Mitgliederzahl zurück.
     *
     * @param  callable(string): void  $write
     */
    public function writeExport(Tenant $tenant, callable $write): int
    {
        $json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $write('{"exported_at":'.$json(now()->toIso8601String())
            .',"tenant":'.$json($tenant->only(['id', 'slug', 'name', 'status', 'created_at']))
            .',"branding":'.$json((object) collect($tenant->branding?->toArray() ?? [])->except(['logo_asset_id', 'favicon_asset_id'])->all())
            .',"members":[');

        $count = 0;
        TenantUser::with('user')->where('tenant_id', $tenant->id)
            ->lazyById(200, 'user_id')
            ->each(function (TenantUser $m) use ($tenant, $write, $json, &$count) {
                if (! $m->user) {
                    return;
                }
                $write(($count++ > 0 ? ',' : '').$json([
                    'role' => $m->role,
                    'status' => $m->status,
                    'member_since' => $m->created_at?->toIso8601String(),
                    // Alle personenbezogenen Daten je Mitglied wie beim Selbst-Export (Art. 15/20 DSGVO).
                    'data' => $this->personalData->export($m->user, $tenant, forSchool: true),
                ]));
            });

        $write('],"open_invitations":'.$json(TenantInvitation::where('tenant_id', $tenant->id)->whereNull('accepted_at')->get(['email', 'role', 'created_at'])->toArray())
            .',"coupons":'.$json(Coupon::where('tenant_id', $tenant->id)->with(['course:id,code', 'product:id,code'])->get()
                ->map(fn (Coupon $c) => ['code' => $c->code, 'course' => $c->course?->code, 'product' => $c->product?->code, 'status' => $this->couponExportStatus($c), 'redeemed_at' => $c->redeemed_at?->toIso8601String()])
                ->all())
            // Lerngruppen mit Plätzen; offene Einladungen mit Adresse (wie open_invitations).
            .',"learning_groups":'.$json(LearningGroup::where('tenant_id', $tenant->id)->with(['owner', 'members.user'])->orderBy('created_at')->get()
                ->map(fn (LearningGroup $g) => [
                    'name' => $g->name, 'status' => $g->status, 'seats' => $g->seats, 'scopes' => $g->scopes,
                    'owner' => $g->owner?->email, 'valid_from' => $g->valid_from?->toIso8601String(), 'valid_until' => $g->valid_until?->toIso8601String(),
                    'source_reference' => $g->source_reference,
                    'members' => $g->members->whereIn('status', ['invited', 'active'])->map(fn (LearningGroupMember $m) => [
                        'status' => $m->status, 'email' => $m->user?->email ?? $m->email,
                        'invited_at' => $m->invited_at?->toIso8601String(), 'joined_at' => $m->joined_at?->toIso8601String(),
                    ])->values()->all(),
                ])->all())
            .'}');

        return $count;
    }

    /**
     * @return array{members: int, deleted_accounts: int}
     */
    public function delete(Tenant $tenant, User $actor): array
    {
        if ($tenant->status !== 'closed') {
            throw new RuntimeException('Nur eine geschlossene Bootsschule kann gelöscht werden.');
        }

        $branding = $tenant->branding;
        $assetIds = array_filter([$branding?->logo_asset_id, $branding?->favicon_asset_id]);
        $userIds = TenantUser::where('tenant_id', $tenant->id)->pluck('user_id')->all();
        $summary = ['slug' => $tenant->slug, 'name' => $tenant->name];

        $deletedAccounts = DB::transaction(function () use ($tenant, $userIds) {
            // Protokoll der Schule (enthält u. a. Adressen und Formulardaten).
            AuditLog::where('tenant_id', $tenant->id)->delete();
            AuditLog::where('entity_type', 'tenant')->where('entity_id', $tenant->id)->delete();
            TenantInvitation::where('tenant_id', $tenant->id)->delete();
            // Review-Runde 13: Exportdateien der App (Zeilen gehen per Kaskade).
            ApiDataExport::deleteFilesAfterCommit(ApiDataExport::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('path'));
            // Shopkonten der Schule: kein Einkauf mehr, Kontaktdaten aus dem Shop
            // nicht weiter aufbewahren; ausgehende Ereignisse ohne Name/Adresse.
            $shopAccountIds = DB::table('commerce_shop_account')->where('tenant_id', $tenant->id)->pluck('id');
            foreach ($shopAccountIds as $shopAccountId) {
                DB::table('commerce_shop_account')->where('id', $shopAccountId)->update([
                    'email' => 'geloescht-'.$shopAccountId.'@invalid.invalid', 'contact_name' => null, 'company_name' => null, 'vat_id' => null,
                    'user_id' => null, 'status' => DB::raw("CASE WHEN status = 'rejected' THEN 'rejected' ELSE 'revoked' END"), 'revoked_at' => now(),
                ]);
            }
            DB::table('commerce_event')->where('tenant_id', $tenant->id)
                ->update(['payload' => DB::raw("payload - 'email' - 'first_name' - 'last_name'")]);

            $tenant->delete();

            // Konten ohne weitere Mitgliedschaft (keine Superadmins) löschen.
            $orphans = User::whereIn('id', $userIds)
                ->where('is_superadmin', false)
                ->whereDoesntHave('tenantMemberships')
                ->get();
            foreach ($orphans as $user) {
                // Gleicher Lösch-Pfad wie die Kontolöschung (Shopkonten,
                // Shop-Ereignisse, plattformweites Protokoll).
                $this->erasure->eraseOrphanAccount($user);
            }

            return $orphans->count();
        });

        // Logo und Favicon erst nach dem Löschen der Schule (Fremdschlüssel im Branding).
        foreach ($assetIds as $assetId) {
            $asset = MediaAsset::find($assetId);
            if ($asset) {
                Storage::disk('public')->delete($asset->getRawOriginal('storage_path'));
                $asset->delete();
            }
        }

        // Nur Kennzahlen, keine personenbezogenen Daten.
        AuditLog::record(null, $actor->id, 'superadmin.tenant.delete', 'tenant', null, null, $summary + [
            'members' => count($userIds), 'deleted_accounts' => $deletedAccounts,
        ]);

        return ['members' => count($userIds), 'deleted_accounts' => $deletedAccounts];
    }

    /**
     * QA-D-2: nicht eingelöste Codes löscht der Trigger
     * tenant_delete_unredeemed_coupons (Migration 000473) mit der Schule --
     * „offen“ allein ließe glauben, sie blieben danach einlösbar.
     */
    private function couponExportStatus(Coupon $coupon): string
    {
        return $coupon->isUsable() ? 'offen, wird beim Löschen der Schule gelöscht' : $coupon->statusLabel();
    }
}
