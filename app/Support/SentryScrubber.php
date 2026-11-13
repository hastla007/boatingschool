<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Entfernt vor dem Versand an Sentry Geheimnisse und personenbezogene Daten,
 * die send_default_pii=false nicht abdeckt: Tokens in URL-Pfaden
 * (Passwort-/Einrichtungslink, Support-Ansicht, Verknüpfung), Signaturen in
 * Query-Strings und E-Mail-Adressen in Fehlermeldungen (z. B. Postgres
 * „Key (email)=(…) already exists“).
 */
class SentryScrubber
{
    private const PATH_TOKENS = '#/(reset-password|impersonate|account-setup|invitations?|email/verify|confirm-email)/[^/?\s]+#i';

    private const QUERY_SECRETS = '/(signature|token|code|expires|email)=[^&\s]*/i';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    public static function scrub(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        if (isset($request['url'])) {
            $request['url'] = self::text((string) $request['url']);
        }
        if (isset($request['query_string'])) {
            $request['query_string'] = self::text((string) $request['query_string']);
        }
        unset($request['cookies'], $request['data']);
        $event->setRequest($request);

        if ($event->getMessage() !== null) {
            $event->setMessage(self::text($event->getMessage()), $event->getMessageParams(), $event->getMessageFormatted() !== null ? self::text($event->getMessageFormatted()) : null);
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }
        $event->setBreadcrumb(array_map(function (Breadcrumb $crumb) {
            return $crumb->getMessage() !== null ? $crumb->withMessage(self::text($crumb->getMessage())) : $crumb;
        }, $event->getBreadcrumbs()));

        return $event;
    }

    public static function text(string $text): string
    {
        $text = preg_replace(self::PATH_TOKENS, '/$1/[entfernt]', $text) ?? $text;
        $text = preg_replace(self::QUERY_SECRETS, '$1=[entfernt]', $text) ?? $text;

        return preg_replace(self::EMAIL, '[E-Mail]', $text) ?? $text;
    }
}
