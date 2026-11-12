<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommerceLinkCode;
use App\Models\CommerceShopAccount;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop verknüpfen (docs/commerce/README.md): Inhaber/Admins erzeugen einen
 * kurzlebigen Einmalcode, den sie in ihrem Shopkonto eingeben -- so wird
 * das Shopkonto an die Schule gebunden (E-Mail-Gleichheit allein reicht
 * nicht). Verknüpfungen lassen sich hier wieder lösen.
 */
class ShopLinkController extends Controller
{
    public function generate(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        // Ältere, noch offene Codes dieser Person verfallen.
        CommerceLinkCode::where('tenant_id', $tenant->id)->where('created_by_user_id', $request->user()->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->update(['expires_at' => now()]);

        $code = CommerceLinkCode::generate();
        CommerceLinkCode::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $request->user()->id,
            'code_hash' => CommerceLinkCode::hash($code),
            'expires_at' => now()->addMinutes((int) config('commerce.link_code_minutes', 15)),
            'created_at' => now(),
        ]);
        AuditLog::record($tenant->id, $request->user()->id, 'commerce.link_code.created', 'tenant', $tenant->id, null, null);

        // Der Code steht nur einmal in der Antwort, gespeichert ist nur sein Hash.
        return back()->with('shopLinkCode', $code)->with('status', 'Verknüpfungscode erzeugt.');
    }

    public function unlink(Request $request, TenantContext $tenantContext, string $account): Response
    {
        $tenant = $tenantContext->tenant();
        $shopAccount = CommerceShopAccount::where('id', $account)->where('tenant_id', $tenant->id)->firstOrFail();
        $shopAccount->forceFill(['status' => CommerceShopAccount::REVOKED, 'revoked_at' => now()])->save();
        AuditLog::record($tenant->id, $request->user()->id, 'commerce.account.unlinked', 'commerce_shop_account', $shopAccount->id, null, []);

        return back()->with('status', 'Verknüpfung mit dem Shopkonto '.$shopAccount->email.' gelöst.');
    }
}
