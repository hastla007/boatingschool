<?php

namespace App\Support;

use App\Notifications\AccountInvitation;
use App\Notifications\AnnouncementPublished;
use App\Notifications\CouponRedeemed;
use App\Notifications\EntitlementExpiringSoon;
use App\Notifications\InactiveAccountDeletionWarning;
use App\Notifications\LearningReminder;
use App\Notifications\PraxisExamAppointmentUpdated;
use App\Notifications\TenantInvitationNotification;
use App\Notifications\WillkommensboxOrderShipped;

/**
 * Mails, die eine Bootsschule an ihre Teilnehmer bzw. ihr Team schickt
 * (Einladung, Terminvorschlag, Freischaltung …). Antworten darauf gehen an
 * die bestätigte Support-Adresse der Schule (Reply-To, siehe
 * AppServiceProvider::configureHealthCheck); fehlt sie, bekommen die Mails
 * stattdessen den Hinweis, nicht zu antworten (AddsNoReplyHint).
 * Konto- und Sicherheitsmails der Plattform gehören bewusst nicht dazu.
 */
class SchoolMails
{
    /** @var list<class-string> */
    public const NOTIFICATIONS = [
        TenantInvitationNotification::class, AccountInvitation::class, CouponRedeemed::class,
        EntitlementExpiringSoon::class, LearningReminder::class,
        PraxisExamAppointmentUpdated::class, AnnouncementPublished::class,
        WillkommensboxOrderShipped::class,
        // Lösch-Vorwarnung bei Inaktivität: im Namen der (einzigen) Schule.
        InactiveAccountDeletionWarning::class,
    ];

    /** Ob Antworten auf Mails der aktuellen Schule an deren bestätigte Support-Adresse gehen. */
    public static function hasVerifiedReplyTo(): bool
    {
        $branding = MailSchool::current()?->branding;

        return (bool) ($branding?->support_email && $branding->support_email_verified_at);
    }
}
