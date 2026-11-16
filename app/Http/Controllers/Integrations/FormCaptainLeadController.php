<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\FormCaptain\LeadIntake;
use App\Integrations\FormCaptain\LeadResponse;
use App\Integrations\FormCaptain\LeadSchema;
use App\Models\FormCaptainLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lead-Eingang für Form Captain, /api/integrations/v1/form-captain
 * (docs/integrations/form-captain-leads.md). Signatur, Wiedergabeschutz und
 * Drosselung prüft VerifyFormCaptainSignature vorher. Getrennt von der
 * Commerce-API: keine Commerce-Rechte, kein Mandant, kein Konto.
 */
class FormCaptainLeadController extends Controller
{
    /** POST /leads */
    public function store(Request $request, LeadIntake $intake): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191 || preg_match('/^[A-Za-z0-9:._\-]+$/', $idempotencyKey) !== 1) {
            return LeadResponse::make(400, 'bad_request', 'Idempotency-Key fehlt oder ist ungültig.');
        }
        $body = $request->getContent();
        $data = json_decode($body, true, 16);
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return LeadResponse::make(400, 'bad_request', 'Der Body ist kein JSON-Objekt.');
        }
        $errors = LeadSchema::validate($data);
        if ($errors !== []) {
            return LeadResponse::make(422, 'schema_invalid', 'Der Body verletzt das Schema '.LeadSchema::ID.'.', ['errors' => $errors]);
        }
        if (! $this->siteAllowed((string) $data['source']['site_url'])) {
            return LeadResponse::make(403, 'site_not_allowed', 'Diese Website ist für den Lead-Eingang nicht freigegeben.');
        }

        return $intake->accept($data, (string) $request->attributes->get('form_captain_key_id'), $idempotencyKey, $body);
    }

    /** POST /ping -- Verbindungstest (A.7), legt nichts an. */
    public function ping(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if ($data !== []) {
            return LeadResponse::make(400, 'bad_request', 'Der Verbindungstest erwartet den Body {}.');
        }

        return response()->json([
            'ok' => true,
            'schema' => LeadSchema::ID,
            'key_id' => (string) $request->attributes->get('form_captain_key_id'),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** DELETE /leads/{leadId} -- Löschung aus Form Captain (A.8), idempotent: 204 oder 404. */
    public function destroy(Request $request, string $leadId): Response
    {
        if ($request->getContent() !== '') {
            return LeadResponse::make(400, 'bad_request', 'Die Löschung erwartet keinen Body.');
        }
        $deleted = FormCaptainLead::where('public_id', $leadId)->delete();

        return $deleted > 0
            ? response()->noContent()->header('Cache-Control', 'no-store, private')
            : LeadResponse::make(404, 'not_found', 'Lead nicht (mehr) vorhanden.');
    }

    /** Ohne Liste ist jede Website mit gültigem Schlüssel erlaubt. */
    private function siteAllowed(string $siteUrl): bool
    {
        $allowed = (array) config('form_captain.allowed_site_urls', []);
        if ($allowed === []) {
            return true;
        }
        $origin = static function (string $url): string {
            $parts = parse_url($url);

            return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''));
        };

        return in_array($origin($siteUrl), array_map($origin, $allowed), true);
    }
}
