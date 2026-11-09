<?php

namespace App\Models;

use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jede Zeile hier ist eine technische Protokollnotiz (action + entity_type/
 * -id + before/after). description() übersetzt das für "Letzte Aktivitäten"
 * im Bootsschul-Dashboard (admin/tabs/dashboard.blade.php) in einen
 * lesbaren deutschen Satz mit handelnder Person und -- wo zutreffend --
 * dem betroffenen Teilnehmer, statt des rohen Aktionscodes.
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Protokolltexte der neueren Aktionen (Satz nach dem Namen der handelnden Person). */
    private const SIMPLE_DESCRIPTIONS = [
        'entitlement.extend' => 'hat einen Kurszugang verlängert.',
        'participants.export' => 'hat die Teilnehmerliste exportiert.',
        'participant.invite_resent' => 'hat eine Einladung erneut verschickt.',
        'participant.remove' => 'hat einen Teilnehmer samt Lerndaten gelöscht.',
        'profile.export' => 'hat die eigenen Daten heruntergeladen.',
        'participants.remind_inactive' => 'hat inaktive Teilnehmer ans Lernen erinnert.',
        'announcement.create' => 'hat eine Mitteilung an Teilnehmer veröffentlicht.',
        'announcement.delete' => 'hat eine Mitteilung an Teilnehmer gelöscht.',
        'team.invite' => 'hat ein Teammitglied eingeladen.',
        'team.invite_pending' => 'hat eine Team-Einladung an ein bestehendes Konto verschickt.',
        'team.role_update' => 'hat die Rolle eines Teammitglieds geändert.',
        'team.transfer_ownership' => 'hat die Inhaberschaft übergeben.',
        'team.two_factor_policy' => 'hat die 2FA-Pflicht fürs Team geändert.',
        'team.instructor_courses' => 'hat die Kurse eines Ausbilders festgelegt.',
        'two_factor.enable' => 'hat die Zwei-Faktor-Anmeldung eingeschaltet.',
        'two_factor.disable' => 'hat die Zwei-Faktor-Anmeldung ausgeschaltet.',
        'superadmin.impersonate.start' => 'hat die Support-Ansicht („Als Nutzer ansehen“) gestartet.',
        'superadmin.impersonate.stop' => 'hat die Support-Ansicht beendet.',
        'superadmin.impersonate.expired' => '– Support-Ansicht nach Zeitablauf automatisch beendet.',
        'superadmin.impersonate.blocked' => 'hat in der Support-Ansicht eine Änderung versucht (gesperrt).',
        'superadmin.content_import' => 'hat Inhalte importiert.',
        'superadmin.coupon.void' => 'hat Codes gesperrt.',
        'superadmin.exam_rule_set.update' => 'hat Prüfungsregeln geändert.',
        'superadmin.mail_templates.update' => 'hat Mailvorlagen geändert.',
        'superadmin.module.update' => 'hat ein Modul geändert.',
        'superadmin.navigation_chart.update' => 'hat eine Navigationskarte geändert.',
        'superadmin.navigation_chart.delete' => 'hat eine Navigationskarte gelöscht.',
        'superadmin.navigation_task.update' => 'hat eine Navigationsaufgabe geändert.',
        'superadmin.platform_announcement.create' => 'hat eine Mitteilung an die Schulen veröffentlicht.',
        'superadmin.platform_announcement.delete' => 'hat eine Mitteilung an die Schulen gelöscht.',
        'superadmin.praxis_task.create' => 'hat eine Praxisaufgabe angelegt.',
        'superadmin.praxis_task.update' => 'hat eine Praxisaufgabe geändert.',
        'superadmin.product.update' => 'hat ein Produkt geändert.',
        'superadmin.tenant.custom_domain' => 'hat die eigene Domain einer Bootsschule geändert.',
        'superadmin.tenant.delete' => 'hat eine Bootsschule gelöscht.',
        'superadmin.tenant.export' => 'hat die Daten einer Bootsschule exportiert.',
        'superadmin.user.delete' => 'hat ein Nutzerkonto gelöscht.',
        'superadmin.user.membership_delete' => 'hat eine Mitgliedschaft entfernt.',
        'superadmin.user.two_factor_reset' => 'hat die Zwei-Faktor-Anmeldung eines Kontos zurückgesetzt.',
        'superadmin.video_lesson.create' => 'hat eine Videolektion angelegt.',
        'superadmin.video_lesson.delete' => 'hat eine Videolektion gelöscht.',
        'superadmin.video_module.create' => 'hat ein Videokapitel angelegt.',
        'superadmin.video_module.delete' => 'hat ein Videokapitel gelöscht.',
    ];

    /** Zahl der in dieser Anfrage geschriebenen Einträge (siehe AuditSuperadminChanges). */
    public static int $recordedThisRequest = 0;

    public static function record(
        ?string $tenantId,
        ?string $actorUserId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before = null,
        ?array $after = null,
    ): self {
        // Support-Ansicht: der Eintrag gehört dem Superadmin, nicht der
        // Person, als die er die Plattform gerade ansieht.
        $impersonation = Impersonation::current();
        if ($impersonation && $actorUserId !== null && $actorUserId !== $impersonation['impersonator_id']) {
            $after = ($after ?? []) + ['support_ansicht_als' => $actorUserId];
            $actorUserId = $impersonation['impersonator_id'];
        }

        static::$recordedThisRequest++;

        return static::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }

    /** Review-Runde 11: Nachschlage-Cache je Request (vorher je Eintrag eigene Abfragen). */
    private static array $lookupCache = [];

    /** Nutzer, Codes und Kurse einer Seite von Einträgen in drei Abfragen vorladen. */
    public static function preloadFor(iterable $entries): void
    {
        $entries = collect($entries);
        self::$lookupCache = [];
        $uuid = fn ($v) => is_string($v) && Str::isUuid($v);
        $data = fn ($e, $k) => $e->after_data[$k] ?? $e->before_data[$k] ?? null;
        $userIds = $entries->flatMap(fn ($e) => [$e->actor_user_id, $e->entity_id, $data($e, 'user_id'), $data($e, 'learner_id'), $data($e, 'redeemed_by_user_id')])->filter($uuid)->unique();
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            self::$lookupCache[User::class][$user->id] = $user;
        }
        foreach (Coupon::whereIn('id', $entries->where('entity_type', 'coupon')->pluck('entity_id')->filter($uuid)->unique())->get() as $coupon) {
            self::$lookupCache[Coupon::class][$coupon->id] = $coupon;
        }
        foreach (CourseDefinition::withoutGlobalScopes()->whereIn('id', $entries->map(fn ($e) => $data($e, 'course_id'))->filter($uuid)->unique())->get() as $course) {
            self::$lookupCache[CourseDefinition::class][$course->id] = $course;
        }
    }

    private static function cachedUser(mixed $id): ?User
    {
        return self::cached(User::class, $id);
    }

    private static function cached(string $class, mixed $id): mixed
    {
        if (! is_string($id) || $id === '') {
            return null;
        }
        // Nur vorgeladene Treffer aus dem Cache; alles andere frisch (kein
        // veralteter Stand über Requests hinweg).
        if (array_key_exists($id, self::$lookupCache[$class] ?? [])) {
            return self::$lookupCache[$class][$id];
        }

        return $class === CourseDefinition::class ? CourseDefinition::withoutGlobalScopes()->find($id) : $class::find($id);
    }

    public function description(): string
    {
        // Eigene Aktionen eines inzwischen gelöschten Kontos (actor pseudonymisiert).
        $actorName = ($this->actor_user_id ? self::cachedUser($this->actor_user_id)?->name : null)
            ?? (str_starts_with($this->action, 'profile.') ? 'Ein gelöschtes Konto' : 'System');

        return match (true) {
            $this->action === 'coupon.redeem' => $this->describeCouponRedeem($actorName),
            $this->action === 'willkommensbox-order.ship' => $this->describeWillkommensboxShip($actorName),
            str_starts_with($this->action, 'entitlement.') => $this->describeEntitlementChange($actorName),
            $this->action === 'praxis-exam-appointment.propose' => $this->describePraxisAppointmentPropose($actorName),
            $this->action === 'participant.update' => $this->describeParticipantUpdate($actorName),
            $this->action === 'participant.invite' => $this->describeParticipantInvite($actorName),
            $this->action === 'participant.invite_pending' => "{$actorName} hat eine Einladung an eine bereits bestehende E-Mail-Adresse verschickt.",
            $this->action === 'participant.membership_update' => $this->describeParticipantMembershipUpdate($actorName),
            $this->action === 'branding.update' => "{$actorName} hat das Branding aktualisiert.",
            $this->action === 'webshop-links.update' => "{$actorName} hat die Webshop-Links aktualisiert.",
            $this->action === 'course-selection.update' => "{$actorName} hat die Kursauswahl aktualisiert.",
            $this->action === 'addons.update' => "{$actorName} hat die Zusatzleistungen aktualisiert.",
            $this->action === 'course-promo-banners.create' => "{$actorName} hat ein Kursempfehlungs-Banner angelegt.",
            $this->action === 'course-promo-banners.update' => "{$actorName} hat ein Kursempfehlungs-Banner aktualisiert.",
            $this->action === 'course-promo-banners.delete' => "{$actorName} hat ein Kursempfehlungs-Banner gelöscht.",
            $this->action === 'tenant-course-locations.create' => "{$actorName} hat einen Praxis-/Prüfungsort angelegt.",
            $this->action === 'tenant-course-locations.update' => "{$actorName} hat einen Praxis-/Prüfungsort aktualisiert.",
            $this->action === 'tenant-course-locations.delete' => "{$actorName} hat einen Praxis-/Prüfungsort gelöscht.",
            // Review-Runde 3: bisher als roher Schlüssel angezeigt.
            $this->action === 'coupon.redeem_renewal' => "{$actorName} hat einen Verlängerungscode eingelöst.",
            $this->action === 'exam-registration.update' => "{$actorName} hat die Prüfungsanmeldung je Kurs aktualisiert.",
            $this->action === 'invitation.accept' => "{$actorName} hat eine Einladung angenommen.",
            $this->action === 'praxis-exam-appointment.withdraw' => "{$actorName} hat einen Terminvorschlag zurückgezogen.",
            $this->action === 'praxis-exam-appointment.cancel' => "{$actorName} hat einen bestätigten Termin abgesagt.",
            $this->action === 'praxis-form-submission.update' => "{$actorName} hat eine Praxis-Anmeldung bearbeitet.",
            $this->action === 'praxis-form-submission.delete' => "{$actorName} hat eine Praxis-Anmeldung gelöscht.",
            $this->action === 'superadmin.coupon.create' => "{$actorName} hat Codes angelegt.",
            $this->action === 'superadmin.coupon.export' => "{$actorName} hat Codes exportiert.",
            $this->action === 'superadmin.settings.update' => "{$actorName} hat Plattform-Einstellungen geändert.",
            $this->action === 'superadmin.tenant.create' => "{$actorName} hat eine Bootsschule angelegt.",
            $this->action === 'superadmin.tenant.update' => "{$actorName} hat eine Bootsschule geändert.",
            $this->action === 'superadmin.user.create' => "{$actorName} hat ein Nutzerkonto angelegt.",
            $this->action === 'superadmin.user.update' => "{$actorName} hat ein Nutzerkonto geändert.",
            $this->action === 'superadmin.user.membership_update' => "{$actorName} hat eine Mitgliedschaft geändert.",
            $this->action === 'superadmin.change' => "{$actorName} hat im Superadmin-Bereich geändert: ".($this->after_data['route'] ?? '—').'.',
            isset(self::SIMPLE_DESCRIPTIONS[$this->action]) => "{$actorName} ".self::SIMPLE_DESCRIPTIONS[$this->action],
            default => "{$actorName}: {$this->action}",
        };
    }

    /**
     * Bug-Review (Codes im Klartext im Log): zeigte den eingelösten Code
     * bisher im Klartext an -- dieser Satz landet direkt im "Letzte
     * Aktivitäten"-Feed der Bootsschule (admin/tabs/dashboard.blade.php),
     * sichtbar für jeden Admin/Owner. Der Kurs-/Produktname reicht aus,
     * um die Aktion verständlich zu beschreiben, ohne den Code selbst
     * preiszugeben.
     */
    private function describeCouponRedeem(string $actorName): string
    {
        $coupon = self::cached(Coupon::class, $this->entity_id);
        $learnerId = $this->after_data['user_id'] ?? null;
        $learnerName = ($learnerId ? self::cachedUser($learnerId)?->name : null) ?? 'einem Teilnehmer';
        $courseOrProductName = $coupon?->course?->name ?? $coupon?->product?->name;
        $what = $courseOrProductName
            ? 'einen Code ('.$courseOrProductName.')'
            : 'einen Code';

        // Beim Selbst-Einlösen (/coupons/redeem) ist der Teilnehmer selbst
        // der Akteur -- dann braucht es kein "für sich selbst" im Satz.
        if ($learnerId && $learnerId === $this->actor_user_id) {
            return "{$actorName} hat {$what} eingelöst.";
        }

        return "{$actorName} hat {$what} für {$learnerName} eingelöst.";
    }

    private function describeWillkommensboxShip(string $actorName): string
    {
        $learnerId = $this->after_data['user_id'] ?? null;
        $learnerName = ($learnerId ? self::cachedUser($learnerId)?->name : null) ?? 'einem Teilnehmer';

        return "{$actorName} hat die Willkommensbox von {$learnerName} als versendet markiert.";
    }

    private function describeEntitlementChange(string $actorName): string
    {
        $data = $this->after_data ?? $this->before_data ?? [];
        $learnerId = $data['user_id'] ?? null;
        $courseId = $data['course_id'] ?? null;
        // Nach „für“ Akkusativ; ein gelöschter Kurs ohne Anführungszeichen.
        $learnerName = ($learnerId ? self::cachedUser($learnerId)?->name : null) ?? 'einen Teilnehmer';
        $courseName = $courseId ? self::cached(CourseDefinition::class, $courseId)?->name : null;

        $verb = match ($this->action) {
            'entitlement.create' => 'freigeschaltet',
            'entitlement.extend' => 'verlängert',
            'entitlement.suspend' => 'gesperrt',
            'entitlement.revoke' => 'widerrufen',
            'entitlement.reactivate' => 'wieder aktiviert',
            default => 'geändert',
        };

        return $courseName
            ? "{$actorName} hat den Kurs \"{$courseName}\" für {$learnerName} {$verb}."
            : "{$actorName} hat einen Kurszugang für {$learnerName} {$verb}.";
    }

    private function describePraxisAppointmentPropose(string $actorName): string
    {
        $submissionId = $this->after_data['praxis_form_submission_id'] ?? null;
        $learnerName = ($submissionId ? PraxisFormSubmission::with('user')->find($submissionId)?->user?->name : null) ?? 'einem Teilnehmer';
        $label = $this->after_data['label'] ?? null;

        return $label ? "{$actorName} hat {$learnerName} \"{$label}\" vorgeschlagen." : "{$actorName} hat {$learnerName} einen Termin vorgeschlagen.";
    }

    private function describeParticipantUpdate(string $actorName): string
    {
        $learnerName = ($this->entity_id ? self::cachedUser($this->entity_id)?->name : null) ?? 'einem Teilnehmer';

        return "{$actorName} hat die Adresse von {$learnerName} aktualisiert.";
    }

    private function describeParticipantInvite(string $actorName): string
    {
        $learnerName = ($this->entity_id ? self::cachedUser($this->entity_id)?->name : null) ?? ($this->after_data['email'] ?? 'einen Teilnehmer');

        return "{$actorName} hat {$learnerName} eingeladen.";
    }

    private function describeParticipantMembershipUpdate(string $actorName): string
    {
        $learnerName = ($this->entity_id ? self::cachedUser($this->entity_id)?->name : null) ?? 'einem Teilnehmer';
        $status = $this->after_data['status'] ?? null;

        $verb = match ($status) {
            'active' => 'entsperrt',
            'suspended' => 'gesperrt',
            'closed' => 'entfernt',
            default => 'geändert',
        };

        return "{$actorName} hat die Mitgliedschaft von {$learnerName} {$verb}.";
    }
}
