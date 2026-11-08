<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstructorCourse;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountSetupLink;
use App\Services\ParticipantInviter;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Team der Bootsschule selbst verwalten: Ausbilder und Admins einladen,
 * Rollen ändern, Inhaberschaft übergeben -- bisher ging das nur über den
 * Superadmin.
 *
 * Rechte: Ein Admin verwaltet nur Lernende und Ausbilder; Admins anlegen
 * oder ändern und die Inhaberschaft übergeben darf nur der Inhaber. Die
 * eigene Rolle ändert niemand selbst, und es bleibt immer genau ein Inhaber.
 */
class TeamController extends Controller
{
    /** Rollen, die eine Schule selbst vergeben kann. */
    private const ASSIGNABLE = ['learner', 'instructor', 'admin'];

    public function invite(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $actorRole = $request->user()->roleForTenant($tenant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::in($actorRole === 'owner' ? ['instructor', 'admin'] : ['instructor'])],
        ]);

        // Plattformweite Suche wie bei der Teilnehmer-Einladung (RLS-frei).
        $existingUser = User::on('pgsql_admin')->where('email', $validated['email'])->first();

        if ($existingUser) {
            $alreadyMember = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->exists();
            if ($alreadyMember) {
                $isLearner = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->value('role') === 'learner';

                return back()->withErrors(['team_email' => $isLearner
                    ? 'Diese Person ist bereits Teilnehmer Ihrer Bootsschule. Ins Team holen Sie sie auf ihrer Teilnehmerseite unter „Ins Team holen“.'
                    : 'Diese Person ist bereits Mitglied Ihrer Bootsschule – ändern Sie die Rolle in der Team-Liste.']);
            }

            // Bestehendes Konto: Einladung, die die Person selbst annimmt
            // (siehe ParticipantController::invite, Sicherheitsreview H2).
            // Review-Runde 9: offene Einladung derselben Rolle wiederverwenden
            // und je Adresse höchstens eine Mail am Tag (Schutz vor Mail-Flut).
            if (! ParticipantInviter::mayMailAddress($tenant, $existingUser->email)) {
                return back()->with('status', 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.');
            }
            $invitation = TenantInvitation::where('tenant_id', $tenant->id)->where('role', $validated['role'])->whereNull('accepted_at')
                ->whereRaw('lower(email) = ?', [mb_strtolower($existingUser->email)])->where('created_at', '>=', now()->subDays(7))
                ->latest('created_at')->first()
                ?? TenantInvitation::create([
                    'tenant_id' => $tenant->id,
                    'email' => $existingUser->email,
                    'role' => $validated['role'],
                    'invited_by_user_id' => $request->user()->id,
                ]);
            $mailSent = ParticipantInviter::sendExistingAccountInvitation($existingUser, $tenant, $invitation);
            AuditLog::record($tenant->id, $request->user()->id, 'team.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email, 'role' => $validated['role'],
            ]);
        } else {
            $user = DB::transaction(function () use ($validated, $tenant, $request) {
                $user = User::create([
                    'email' => $validated['email'],
                    'display_name' => $validated['name'],
                    'password' => Hash::make(Str::random(32)),
                    'status' => 'invited',
                    ...User::splitDisplayName($validated['name']),
                ]);
                TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $validated['role'], 'status' => 'active']);
                AuditLog::record($tenant->id, $request->user()->id, 'team.invite', 'app_user', $user->id, null, [
                    'email' => $user->email, 'role' => $validated['role'],
                ]);

                return $user;
            });
            // Wie bei Teilnehmern: der Link zum Passwort setzen ist die Einladung.
            $mailSent = BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, $validated['role']), "Team-Einladung neues Konto {$user->id}");
        }

        // Gleicher Text für bestehendes und neues Konto (kein Konto-Orakel).
        return back()->with('status', $mailSent
            ? 'Einladung verschickt.'
            : 'Einladung angelegt, der Versand der E-Mail ist aber fehlgeschlagen – bitte später erneut einladen.');
    }

    public function updateRole(Request $request, TenantContext $tenantContext, User $user): Response
    {
        $tenant = $tenantContext->tenant();
        $actorRole = $request->user()->roleForTenant($tenant);
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);
        abort_if($user->id === $request->user()->id, 422, 'Die eigene Rolle kann nicht geändert werden.');

        $validated = $request->validate(['role' => ['required', Rule::in(self::ASSIGNABLE)]]);

        // Inhaber wechselt nur über die Übergabe; Admins verwaltet nur der Inhaber.
        if ($membership->role === 'owner') {
            return back()->withErrors(['team' => 'Die Rolle des Inhabers ändert sich nur über „Inhaberschaft übergeben“.']);
        }
        $touchesAdmin = $membership->role === 'admin' || $validated['role'] === 'admin';
        abort_if($touchesAdmin && $actorRole !== 'owner', 403, 'Admins kann nur der Inhaber verwalten.');
        abort_unless(in_array($membership->role, self::ASSIGNABLE, true), 403, 'Diese Rolle verwaltet der Plattform-Betreiber.');

        $before = $membership->only('role');
        $membership->update(['role' => $validated['role']]);
        AuditLog::record($tenant->id, $request->user()->id, 'team.role_update', 'tenant_user', $user->id, $before, ['role' => $validated['role']]);

        return back()->with('status', "Rolle von {$user->name} geändert.");
    }

    public function transferOwnership(Request $request, TenantContext $tenantContext, User $user): Response
    {
        $tenant = $tenantContext->tenant();
        $actor = $request->user();
        abort_unless($actor->roleForTenant($tenant) === 'owner', 403, 'Nur der Inhaber kann die Inhaberschaft übergeben.');
        abort_if($user->id === $actor->id, 422);

        $target = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($target, 404);
        if ($target->role !== 'admin' || $target->status !== 'active') {
            return back()->withErrors(['team' => 'Die Inhaberschaft kann nur an einen aktiven Admin übergeben werden.']);
        }

        DB::transaction(function () use ($tenant, $actor, $target) {
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $actor->id)->update(['role' => 'admin']);
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $target->user_id)->update(['role' => 'owner']);
        });
        AuditLog::record($tenant->id, $actor->id, 'team.transfer_ownership', 'tenant_user', $user->id, ['owner' => $actor->id], ['owner' => $user->id]);

        return back()->with('status', "{$user->name} ist jetzt Inhaber. Sie sind jetzt Admin.");
    }

    /** Kurse, für die ein Ausbilder zuständig ist (leer = alle Kurse der Schule). */
    public function updateCourses(Request $request, TenantContext $tenantContext, User $user): Response
    {
        $tenant = $tenantContext->tenant();
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        abort_unless($membership->role === 'instructor', 404);
        $validated = $request->validate(['course_ids' => ['nullable', 'array'], 'course_ids.*' => ['uuid', 'exists:course_definition,id']]);

        $courseIds = collect($validated['course_ids'] ?? [])->unique()->values();
        $before = InstructorCourse::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('course_id')->all();
        DB::transaction(function () use ($tenant, $user, $courseIds) {
            InstructorCourse::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereNotIn('course_id', $courseIds)->delete();
            foreach ($courseIds->diff(InstructorCourse::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('course_id')) as $courseId) {
                InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $courseId]);
            }
        });
        AuditLog::record($tenant->id, $request->user()->id, 'team.instructor_courses', 'tenant_user', $user->id, ['course_ids' => $before], ['course_ids' => $courseIds->all()]);

        return back()->with('status', $courseIds->isEmpty()
            ? 'Der Ausbilder sieht jetzt alle Teilnehmer.'
            : 'Der Ausbilder sieht jetzt nur Teilnehmer der ausgewählten Kurse.');
    }

    /** 2FA-Pflicht fürs Team ein- oder ausschalten (nur der Inhaber). */
    public function twoFactorPolicy(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        abort_unless($request->user()->roleForTenant($tenant) === 'owner', 403, 'Nur der Inhaber kann die 2FA-Pflicht ändern.');
        $required = $request->boolean('require_team_two_factor');
        if ($required && ! $request->user()->hasTwoFactor()) {
            return back()->withErrors(['two_factor' => 'Bitte richten Sie zuerst für Ihr eigenes Konto die Zwei-Faktor-Anmeldung ein.']);
        }

        $tenant->branding->update(['require_team_two_factor' => $required]);
        AuditLog::record($tenant->id, $request->user()->id, 'team.two_factor_policy', 'tenant_branding', $tenant->id, null, ['required' => $required]);

        return back()->with('status', $required
            ? 'Zwei-Faktor-Anmeldung ist jetzt für alle Team-Mitglieder Pflicht.'
            : 'Die 2FA-Pflicht fürs Team ist aufgehoben.');
    }
}
