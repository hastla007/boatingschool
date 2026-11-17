<?php

namespace Tests\Feature\Integrations;

use App\Models\FormCaptainLead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lead-Inbox im Superadmin (BS-09, BS-13): nur Superadmins sehen und
 * bearbeiten Leads; Bearbeiten, Ablehnen und Löschen legen nie ein Konto an.
 */
class FormCaptainLeadInboxTest extends TestCase
{
    use InteractsWithTenants;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = 'https://inbox-'.Str::lower(Str::random(8)).'.example.test';
    }

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => FormCaptainLead::where('site_url', $this->site)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function makeLead(array $attributes = []): FormCaptainLead
    {
        return $this->onAdmin(fn () => FormCaptainLead::create($attributes + [
            'public_id' => 'L-'.Str::ulid(),
            'status' => FormCaptainLead::NEW,
            'key_id' => 'fc-lead',
            'site_url' => $this->site,
            'form_id' => 7,
            'entry_id' => random_int(1, 1_000_000),
            'purpose' => 'consulting',
            'contact_name' => 'Kim Meer',
            'contact_email' => 'kim-'.Str::lower(Str::random(6)).'@example.test',
            'email_hash' => str_repeat('0', 64),
            'contact_organisation' => 'Segelschule Meer',
            'message' => 'Rückruf bitte',
            'consent_given_at' => now(),
            'consent_text_revision' => 2,
            'submitted_at' => now(),
            'retain_until' => now()->addDays(180),
        ]));
    }

    public function test_only_superadmins_see_the_inbox(): void
    {
        $lead = $this->makeLead();

        $this->get(route('superadmin.leads.index'))->assertRedirect();
        $admin = $this->createTenantUser($this->createTestTenant(), 'admin');
        $this->actingAs($admin)->get(route('superadmin.leads.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('superadmin.leads.show', $lead))->assertForbidden();
        $this->actingAs($admin)->delete(route('superadmin.leads.destroy', $lead))->assertForbidden();
        $this->assertTrue($this->onAdmin(fn () => FormCaptainLead::whereKey($lead->id)->exists()));

        $superadmin = $this->createSuperAdmin();
        $this->actingAs($superadmin)->get(route('superadmin.leads.index'))->assertOk()->assertSee($lead->contact_email)->assertSee('Neu');
        $this->actingAs($superadmin)->get(route('superadmin.leads.index', ['q' => 'segelschule meer']))->assertOk()->assertSee($lead->public_id);
        $this->actingAs($superadmin)->get(route('superadmin.leads.index', ['status' => 'done']))->assertOk()->assertDontSee($lead->public_id);
        // Nach Zweck filtern.
        $support = $this->makeLead(['purpose' => 'support', 'product_id' => 42, 'product_name' => 'SBF-See Onlinekurs', 'product_sku' => 'SBF-SEE', 'customer_ref_id' => 17, 'customer_email' => 'kunde@example.test']);
        $this->actingAs($superadmin)->get(route('superadmin.leads.index', ['purpose' => 'support']))->assertOk()->assertSee($support->public_id)->assertDontSee($lead->public_id)->assertSee('SBF-See Onlinekurs');
        $this->actingAs($superadmin)->get(route('superadmin.leads.index', ['purpose' => 'consulting']))->assertOk()->assertSee($lead->public_id)->assertDontSee($support->public_id);
        $this->actingAs($superadmin)->get(route('superadmin.leads.show', $support))->assertOk()->assertSee('SKU SBF-SEE')->assertSee('Kundennummer 17')->assertSee('kunde@example.test');
        $this->actingAs($superadmin)->get(route('superadmin.leads.show', $lead))->assertOk()->assertSee('Rückruf bitte')->assertSee('Textfassung 2');
    }

    public function test_superadmin_edits_rejects_and_deletes_without_creating_accounts(): void
    {
        $superadmin = $this->createSuperAdmin();
        $users = $this->onAdmin(fn () => User::count());
        $lead = $this->makeLead();

        $this->actingAs($superadmin)->patch(route('superadmin.leads.update', $lead), ['status' => 'in_progress', 'notes' => 'Am Montag anrufen'])
            ->assertRedirect(route('superadmin.leads.show', $lead));
        $fresh = $this->onAdmin(fn () => $lead->fresh());
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame('Am Montag anrufen', $fresh->notes);
        $this->assertSame($superadmin->id, $fresh->handled_by_user_id);
        $this->assertNotNull($fresh->status_changed_at);

        $this->actingAs($superadmin)->patch(route('superadmin.leads.update', $lead), ['status' => 'gewonnen'])->assertSessionHasErrors('status');

        $this->actingAs($superadmin)->post(route('superadmin.leads.reject', $lead), ['rejection_reason' => 'Keine Bootsschule'])->assertRedirect();
        $fresh = $this->onAdmin(fn () => $lead->fresh());
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Keine Bootsschule', $fresh->rejection_reason);
        $this->assertSame('Am Montag anrufen', $fresh->notes);

        $this->actingAs($superadmin)->delete(route('superadmin.leads.destroy', $lead))->assertRedirect(route('superadmin.leads.index'));
        $this->assertFalse($this->onAdmin(fn () => FormCaptainLead::whereKey($lead->id)->exists()));
        $this->assertSame($users, $this->onAdmin(fn () => User::count()), 'kein Konto entstanden');
    }

    public function test_expired_leads_are_deleted_automatically_after_180_days_by_default(): void
    {
        $this->assertTrue(config('form_captain.retention.enabled'), 'Standard: an (Entscheidung 16.11.2026)');
        $this->assertSame(180, config('form_captain.retention.days'));
        $expired = $this->makeLead(['retain_until' => now()->subDay()]);
        $current = $this->makeLead();

        config(['form_captain.retention.enabled' => false]);
        $this->artisan('app:prune-form-captain-leads')->assertSuccessful();
        $this->assertTrue($this->onAdmin(fn () => FormCaptainLead::whereKey($expired->id)->exists()), 'abgeschaltet: nichts gelöscht');

        config(['form_captain.retention.enabled' => true]);
        $this->artisan('app:prune-form-captain-leads')->assertSuccessful();
        $this->assertFalse($this->onAdmin(fn () => FormCaptainLead::whereKey($expired->id)->exists()));
        $this->assertTrue($this->onAdmin(fn () => FormCaptainLead::whereKey($current->id)->exists()));
    }

    public function test_app_role_cannot_read_leads(): void
    {
        $this->makeLead();
        // Die App-Rolle (Mandanten-Anfragen) sieht keine Leads: RLS an, keine Policy, kein GRANT.
        $this->expectException(QueryException::class);
        FormCaptainLead::count();
    }
}
