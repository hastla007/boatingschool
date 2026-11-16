<?php

namespace App\Integrations\FormCaptain;

use App\Models\FormCaptainLead;
use App\Models\FormCaptainLeadRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lead aus einem geprüften Body anlegen (Vertrag A.4 bis A.6).
 *
 * - Idempotenz: derselbe Idempotency-Key mit demselben Body-Hash liefert
 *   dieselbe lead_id (200, Idempotent-Replay: true), mit anderem Body-Hash
 *   409 idempotency_conflict. Gilt über eine Schlüsselrotation hinweg und
 *   auch, wenn der Lead inzwischen gelöscht wurde.
 * - Tagesgrenze je Absenderadresse: darüber kein neuer Lead, sondern
 *   202 accepted_merged mit der bestehenden lead_id.
 *
 * Legt ausschließlich Zeilen in form_captain_lead(_request) an -- nie
 * Benutzer, Schule, Shopkonto oder Codes.
 */
final class LeadIntake
{
    /** @param array<string, mixed> $data geprüft durch LeadSchema */
    public function accept(array $data, string $keyId, string $idempotencyKey, string $body): JsonResponse
    {
        $bodyHash = hash('sha256', $body);
        if ($existing = FormCaptainLeadRequest::where('idempotency_key', $idempotencyKey)->first()) {
            return $this->replay($existing, $bodyHash);
        }

        $emailHash = FormCaptainLead::emailHash((string) $data['contact']['email']);
        try {
            return DB::transaction(function () use ($data, $keyId, $idempotencyKey, $bodyHash, $emailHash) {
                $limit = (int) config('form_captain.rate_limits.per_email_day', 5);
                $recent = FormCaptainLead::where('email_hash', $emailHash)->where('created_at', '>=', now()->subDay());
                if ($limit > 0 && (clone $recent)->count() >= $limit) {
                    $lead = $recent->orderByDesc('created_at')->orderByDesc('public_id')->lockForUpdate()->first();
                    $lead->forceFill(['merged_count' => $lead->merged_count + 1, 'last_merged_at' => now()])->save();
                    $this->remember($keyId, $idempotencyKey, $bodyHash, $lead->public_id, 202, 'accepted_merged');

                    return $this->leadResponse(202, 'accepted_merged', $lead->public_id, $lead->status);
                }

                $lead = FormCaptainLead::create($this->attributes($data, $keyId, $emailHash));
                $this->remember($keyId, $idempotencyKey, $bodyHash, $lead->public_id, 201, 'created');

                return $this->leadResponse(201, 'created', $lead->public_id, $lead->status);
            });
        } catch (UniqueConstraintViolationException) {
            // Gleichzeitige Zustellung desselben Jobs: die andere Anfrage hat gewonnen.
            return $this->replay(FormCaptainLeadRequest::where('idempotency_key', $idempotencyKey)->firstOrFail(), $bodyHash);
        }
    }

    private function replay(FormCaptainLeadRequest $existing, string $bodyHash): JsonResponse
    {
        if (! hash_equals($existing->body_hash, $bodyHash)) {
            return LeadResponse::make(409, 'idempotency_conflict', 'Dieser Idempotency-Key wurde mit anderem Inhalt verwendet.');
        }
        $status = FormCaptainLead::where('public_id', $existing->lead_public_id)->value('status') ?? 'deleted';

        return $this->leadResponse($existing->http_status === 201 ? 200 : $existing->http_status, $existing->code, $existing->lead_public_id, $status, ['Idempotent-Replay' => 'true']);
    }

    private function remember(string $keyId, string $idempotencyKey, string $bodyHash, string $leadId, int $status, string $code): void
    {
        FormCaptainLeadRequest::create([
            'key_id' => $keyId,
            'idempotency_key' => $idempotencyKey,
            'body_hash' => $bodyHash,
            'lead_public_id' => $leadId,
            'http_status' => $status,
            'code' => $code,
        ]);
    }

    /** @param array<string, string> $headers */
    private function leadResponse(int $status, string $code, string $leadId, string $leadStatus, array $headers = []): JsonResponse
    {
        return LeadResponse::make($status, $code, '', ['lead_id' => $leadId, 'status' => $leadStatus], $headers);
    }

    /**
     * Nur die Felder des Schemas; nichts sonst wird gespeichert.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, string $keyId, string $emailHash): array
    {
        $source = $data['source'];
        $contact = $data['contact'];
        $consent = $data['consent'];
        $clean = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'public_id' => 'L-'.Str::ulid(),
            'status' => FormCaptainLead::NEW,
            'key_id' => $keyId,
            'site_url' => $source['site_url'],
            'page_url' => $clean($source['page_url'] ?? null),
            'form_id' => $source['form_id'],
            'entry_id' => $source['entry_id'],
            'plugin_version' => $clean($source['plugin_version'] ?? null),
            'purpose' => $data['purpose'] ?? null,
            'contact_name' => $clean($contact['name'] ?? null),
            'contact_email' => trim((string) $contact['email']),
            'email_hash' => $emailHash,
            'contact_phone' => $clean($contact['phone'] ?? null),
            'contact_organisation' => $clean($contact['organisation'] ?? null),
            'contact_role' => $clean($contact['role'] ?? null),
            'school_size' => $data['school_size'] ?? null,
            'message' => $clean($data['message'] ?? null),
            'consent_given_at' => LeadSchema::parseDateTime($consent['given_at']),
            'consent_text_revision' => $consent['text_revision'],
            'consent_text_hash' => $consent['text_hash'] ?? null,
            'submitted_at' => LeadSchema::parseDateTime($data['submitted_at']),
            'retain_until' => FormCaptainLead::retainUntil(),
        ];
    }
}
