<?php

namespace App\Services;

use App\Models\ApiDataExport;
use App\Models\AuditLog;
use App\Models\Coupon;
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
 * (Mitgliedschaften, Freischaltungen, Lernstände, Anmeldungen, Bestellungen
 * … per ON DELETE CASCADE), ihr Protokoll, Logo und Favicon. Konten, die
 * danach keiner Bootsschule mehr angehören, werden ebenfalls gelöscht;
 * Konten mit weiteren Mitgliedschaften und Superadmins bleiben. Läuft im
 * Superadmin-Bereich auf der Owner-Verbindung (RLS-frei).
 */
class TenantOffboardingService
{
    public function __construct(private PersonalDataExportService $personalData) {}

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
                ->map(fn (Coupon $c) => ['code' => $c->code, 'course' => $c->course?->code, 'product' => $c->product?->code, 'status' => $c->statusLabel(), 'redeemed_at' => $c->redeemed_at?->toIso8601String()])
                ->all())
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

            $tenant->delete();

            // Konten ohne weitere Mitgliedschaft (keine Superadmins) löschen.
            $orphans = User::whereIn('id', $userIds)
                ->where('is_superadmin', false)
                ->whereDoesntHave('tenantMemberships')
                ->get();
            foreach ($orphans as $user) {
                AuditLog::where('entity_type', 'app_user')->where('entity_id', $user->id)->update(['before_data' => null, 'after_data' => null]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('account_setup_tokens')->where('email', $user->email)->delete();
                DB::table('app_user')->where('id', $user->id)->delete();
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
}
