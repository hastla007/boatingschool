<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use App\Notifications\DuplicateRegistrationAttempt;
use App\Support\AgeConfirmation;
use App\Support\RegistrationBotCheck;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bot-Schutz der Selbstregistrierung ohne Dritte: Honeypot, Mindest-
 * Ausfüllzeit (signierter Zeitstempel) und höchstens drei Registrierungs-
 * bzw. Hinweismails je Adresse und Tag. Dazu die Pflicht-Checkbox zum
 * Mindestalter.
 */
class RegistrationBotProtectionTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function register($tenant, array $fields)
    {
        return $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', $fields + [
            'name' => 'Bot Test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);
    }

    private function assertNotCreated(string $email): void
    {
        $this->assertFalse($this->onAdmin(fn () => User::where('email', $email)->exists()));
    }

    public function test_the_form_contains_the_honeypot_the_signed_timestamp_and_the_age_checkbox(): void
    {
        $tenant = $this->createTestTenant();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register')->assertOk()
            ->assertSee('name="'.RegistrationBotCheck::HONEYPOT.'"', false)
            ->assertSee('name="'.RegistrationBotCheck::TIMESTAMP.'"', false)
            ->assertSee('name="age_confirmed"', false)
            ->assertSee(AgeConfirmation::LABEL);
    }

    public function test_bots_get_the_generic_success_response_and_nothing_is_created(): void
    {
        $tenant = $this->createTestTenant();
        $valid = $this->registrationFormFields();
        $cases = [
            'Honeypot ausgefüllt' => [RegistrationBotCheck::HONEYPOT => 'https://spam.example'] + $valid,
            'zu schnell' => [RegistrationBotCheck::TIMESTAMP => RegistrationBotCheck::token(time() - 1)] + $valid,
            'ohne Zeitstempel' => ['age_confirmed' => '1'],
            'gefälschte Signatur' => [RegistrationBotCheck::TIMESTAMP => (time() - 60).'.'.str_repeat('a', 64)] + $valid,
            'Zeitstempel in der Zukunft' => [RegistrationBotCheck::TIMESTAMP => RegistrationBotCheck::token(time() + 600)] + $valid,
        ];

        foreach ($cases as $label => $fields) {
            $email = 'bot-'.md5($label).'@example.test';
            $this->register($tenant, $fields + ['email' => $email])
                ->assertRedirect()->assertSessionHasNoErrors()
                ->assertSessionHas('status', RegisteredUserController::STATUS);
            $this->assertNotCreated($email);
        }

        Notification::assertNothingSent();
    }

    public function test_a_human_with_the_age_confirmation_is_registered(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'mensch-'.uniqid().'@example.test';

        $this->register($tenant, $this->registrationFormFields() + ['email' => $email])
            ->assertSessionHasNoErrors()->assertSessionHas('status', RegisteredUserController::STATUS);

        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->createdUserIds[] = $user->id;
        $this->assertNotNull($user->age_confirmed_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_age_confirmation_is_required(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'ohne-alter-'.uniqid().'@example.test';
        $fields = $this->registrationFormFields();
        unset($fields['age_confirmed']);

        $this->register($tenant, $fields + ['email' => $email])
            ->assertSessionHasErrors(['age_confirmed' => AgeConfirmation::MESSAGE]);
        $this->assertNotCreated($email);
    }

    public function test_at_most_three_registration_mails_per_address_and_day(): void
    {
        $tenant = $this->createTestTenant();
        $existing = $this->createTenantUser($tenant, 'learner');

        // Fünf Versuche im Abstand von gut einer Stunde (die Stunden-Drossel
        // greift so nicht), alle innerhalb eines Tages.
        foreach (range(1, 5) as $attempt) {
            $this->register($tenant, $this->registrationFormFields() + ['email' => $existing->email])
                ->assertSessionHas('status', RegisteredUserController::STATUS);
            $this->travel(61)->minutes();
        }
        Notification::assertSentOnDemandTimes(DuplicateRegistrationAttempt::class, RegisteredUserController::DAILY_MAILS_PER_ADDRESS);

        // Am nächsten Tag geht wieder eine Mail raus.
        $this->travel(1)->days();
        $this->register($tenant, $this->registrationFormFields() + ['email' => $existing->email]);
        Notification::assertSentOnDemandTimes(DuplicateRegistrationAttempt::class, RegisteredUserController::DAILY_MAILS_PER_ADDRESS + 1);
    }
}
