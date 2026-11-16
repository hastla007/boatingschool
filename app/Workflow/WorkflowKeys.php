<?php

namespace App\Workflow;

/**
 * Schlüssel des Workflow-Eingangs (WF-CUST1-05, -06).
 *
 * Eigene Schlüssel-IDs und Geheimnisse, getrennt von der Commerce-API: ein
 * Workflow-Schlüssel, dessen ID oder Geheimnis auch ein Commerce-Schlüssel
 * ist (config commerce.keys, commerce.outbound_secret), gilt als nicht
 * konfiguriert. So kann ein Workflow-Aufruf nie Commerce-Rechte erben und
 * umgekehrt.
 *
 * Rotation: bis zu zwei aktive Schlüssel (WORKFLOW_KEY_ID/_SECRET und
 * …_NEXT). Der Connector wechselt auf den neuen, danach wird der alte aus
 * der Konfiguration entfernt -- ab dann wird er abgelehnt.
 */
final class WorkflowKeys
{
    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_PLATFORM = 'platform';

    /** @return array{secret:string, scopes:list<string>}|null */
    public static function find(string $keyId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            return null;
        }
        $key = self::all()[$keyId] ?? null;

        return $key;
    }

    /** @return array<string, array{secret:string, scopes:list<string>}> nur gültige, von Commerce getrennte Schlüssel */
    public static function all(): array
    {
        $commerceKeys = (array) config('commerce.keys', []);
        $commerceSecrets = array_filter(array_merge(array_values($commerceKeys), [(string) config('commerce.outbound_secret')]));
        $out = [];
        foreach ((array) config('workflow.keys', []) as $id => $key) {
            $secret = is_array($key) ? (string) ($key['secret'] ?? '') : '';
            if ($secret === '' || strlen($secret) < 16 || array_key_exists($id, $commerceKeys) || in_array($secret, $commerceSecrets, true)) {
                continue;
            }
            $scopes = array_values(array_intersect((array) ($key['scopes'] ?? [self::SCOPE_TENANT]), [self::SCOPE_TENANT, self::SCOPE_PLATFORM]));
            $out[(string) $id] = ['secret' => $secret, 'scopes' => $scopes];
        }

        return $out;
    }

    /** Fehlkonfiguration für den Go-live-Check (ohne Werte). @return list<string> */
    public static function problems(): array
    {
        $problems = [];
        $commerceKeys = (array) config('commerce.keys', []);
        $commerceSecrets = array_filter(array_merge(array_values($commerceKeys), [(string) config('commerce.outbound_secret')]));
        foreach ((array) config('workflow.keys', []) as $id => $key) {
            $secret = is_array($key) ? (string) ($key['secret'] ?? '') : '';
            if (array_key_exists($id, $commerceKeys)) {
                $problems[] = 'key_id_reused_from_commerce';
            }
            if ($secret !== '' && in_array($secret, $commerceSecrets, true)) {
                $problems[] = 'secret_reused_from_commerce';
            }
            if ($secret !== '' && strlen($secret) < 16) {
                $problems[] = 'secret_too_short';
            }
        }
        $outbound = (string) config('workflow.outbound_secret');
        if ($outbound !== '' && in_array($outbound, $commerceSecrets, true)) {
            $problems[] = 'outbound_secret_reused_from_commerce';
        }

        return array_values(array_unique($problems));
    }
}
