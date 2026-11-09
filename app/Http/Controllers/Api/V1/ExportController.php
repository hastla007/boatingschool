<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ApiDataExport;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\PersonalDataExportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * DSGVO-Datenexport (Art. 15/20) aus der App: dieselben Daten wie
 * „Meine Daten herunterladen“ im Web (PersonalDataExportService). Die Datei
 * liegt befristet im privaten Speicher und ist nur über einen kurzlebigen,
 * signierten Link abrufbar; danach räumt app:prune-personal-data sie weg.
 */
class ExportController extends Controller
{
    private const DISK = 'local';

    public function __construct(private TenantContext $tenantContext) {}

    public function store(Request $request, PersonalDataExportService $exporter): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();

        $export = ApiDataExport::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'processing', 'created_at' => now()]);
        $path = 'api-exports/'.$export->id.'.json';
        try {
            Storage::disk(self::DISK)->put($path, json_encode($exporter->export($user, $tenant), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            // Review-Runde 13: Fehlschlag sichtbar machen statt „processing“ für immer.
            $export->update(['status' => 'failed']);
            report($e);

            return response()->json(['data' => $this->status($export)], 202);
        }
        $export->update(['status' => 'ready', 'path' => $path, 'expires_at' => now()->addMinutes((int) config('mobile_api.export_ttl_minutes', 60))]);
        AuditLog::record($tenant->id, $user->id, 'profile.export', 'app_user', $user->id, null, ['channel' => 'app']);

        return response()->json(['data' => $this->status($export)], 202);
    }

    public function show(Request $request, string $exportId): JsonResponse
    {
        $export = ApiDataExport::where('id', $exportId)->where('tenant_id', $this->tenantContext->id())->where('user_id', $request->user()->id)->first();
        if (! $export) {
            throw ApiProblem::notFound('Export');
        }

        return response()->json(['data' => $this->status($export)]);
    }

    /** Download über den signierten Link (ohne Bearer-Token, z. B. für das Teilen-Menü des Telefons). */
    public function download(Request $request, string $exportId): Response
    {
        $tenant = Tenant::find((string) $request->query('tenant'));
        if (! $tenant) {
            throw ApiProblem::notFound('Export');
        }
        $this->tenantContext->set($tenant);
        $export = ApiDataExport::where('id', $exportId)->where('tenant_id', $tenant->id)->first();
        if (! $export || $export->status !== 'ready' || ! $export->path || $export->expires_at?->isPast()
            || ! Storage::disk(self::DISK)->exists($export->path)) {
            throw new ApiProblem(410, 'EXPORT_EXPIRED', 'Export abgelaufen', 'Bitte einen neuen Export anfordern.');
        }

        return Storage::disk(self::DISK)->download($export->path, 'meine-daten-'.$tenant->slug.'-'.$export->created_at->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function status(ApiDataExport $export): array
    {
        $expired = $export->status === 'ready' && $export->expires_at?->isPast();
        $ready = $export->status === 'ready' && ! $expired;
        $linkUntil = $ready ? min($export->expires_at, now()->addMinutes(10)) : null;

        return [
            'export_id' => $export->id,
            'status' => $expired ? 'expired' : $export->status,
            'download_url' => $ready
                ? URL::temporarySignedRoute('api.v1.export.download', $linkUntil, ['exportId' => $export->id, 'tenant' => $export->tenant_id])
                : null,
            'download_url_expires_at' => Present::time($linkUntil),
            'expires_at' => Present::time($export->expires_at),
            'created_at' => Present::time($export->created_at),
        ];
    }
}
