<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B6: ohne lang/de zeigte jede Validierungsmeldung den rohen
 * Übersetzungsschlüssel (z. B. "validation.required") statt eines
 * deutschen Textes, weil Laravel 11 standardmäßig keinen lang/-Ordner mehr
 * mitbringt.
 */
class GermanValidationMessagesTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_missing_required_field_shows_a_german_message_not_a_raw_key(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => '',
            'email' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertSessionHasErrors('email');
        $errors = session('errors')->getBag('default');

        $this->assertStringNotContainsString('validation.', $errors->first('email'));
        $this->assertStringContainsString('erforderlich', $errors->first('email'));
    }

    public function test_the_email_attribute_uses_a_friendly_german_label(): void
    {
        $this->assertSame('E-Mail-Adresse', trans('validation.attributes.email'));
    }
}
