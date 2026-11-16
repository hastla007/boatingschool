<?php

namespace Tests\Feature;

use App\Support\FieldErrorAttributes;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Barrierefreiheit (WCAG 1.3.1/3.3.1) in den Superadmin-Formularen: eine
 * Fehlermeldung am Feld ist mit ihm verknüpft (aria-invalid,
 * aria-describedby auf die id der Meldung) -- auch bei select, textarea,
 * Datei- und Auswahlfeldern (@ariaInvalid), nicht nur bei <x-text-input>.
 */
class SuperadminFormErrorsAreLinkedToFieldsTest extends TestCase
{
    use InteractsWithTenants;

    /** Jede per aria-describedby verknüpfte id gibt es auf der Seite. */
    private function assertDescribedByTargetsExist(TestResponse $response): void
    {
        $html = $response->getContent();
        preg_match_all('/aria-describedby="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique(array_merge(...array_map(fn ($ids) => explode(' ', $ids), $matches[1]))) as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "aria-describedby verweist auf fehlende id {$id}");
        }
    }

    public function test_tip_form_links_select_and_textarea_errors(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get(route('superadmin.tips.create'))->assertOk()
            ->assertDontSee('aria-invalid="true"', false);

        $response = $this->actingAs($superadmin)->from(route('superadmin.tips.create'))->followingRedirects()
            ->post(route('superadmin.tips.store'), ['category_id' => '', 'title' => 'Titel', 'body' => ''])->assertOk();
        $response->assertSee('<select id="category_id" aria-invalid="true" aria-describedby="category_id-error"', false)
            ->assertSee('<textarea id="body" aria-invalid="true" aria-describedby="body-error"', false)
            ->assertSee('id="category_id-error"', false)->assertSee('id="body-error"', false)
            ->assertDontSee('aria-describedby="title-error"', false);
        $this->assertDescribedByTargetsExist($response);
    }

    public function test_announcement_form_links_textarea_and_school_selection_errors(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->createTestTenant();

        $response = $this->actingAs($superadmin)->from(route('superadmin.announcements.index'))->followingRedirects()
            ->post(route('superadmin.announcements.store'), ['title' => 'Wartung', 'body' => '', 'audience' => 'team', 'target' => 'selected'])->assertOk();
        $response->assertSee('<textarea id="body" aria-invalid="true" aria-describedby="body-error"', false)
            ->assertSee('value="selected" x-model="target" aria-invalid="true" aria-describedby="tenant_ids-error"', false)
            ->assertSee('id="tenant_ids-error"', false)
            ->assertDontSee('aria-describedby="title-error"', false);
        $this->assertDescribedByTargetsExist($response);
    }

    public function test_settings_and_tenant_forms_link_their_errors(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->from(route('superadmin.settings.edit'))->followingRedirects()
            ->patch(route('superadmin.settings.update'), ['site_name' => '', 'operator_info' => str_repeat('x', 2001)])->assertOk();
        $response->assertSee('<textarea id="operator_info" aria-invalid="true" aria-describedby="operator_info-error"', false)
            ->assertSee('aria-describedby="site_name-error"', false);
        $this->assertDescribedByTargetsExist($response);

        $response = $this->actingAs($superadmin)->from(route('superadmin.tenants.create'))->followingRedirects()
            ->post(route('superadmin.tenants.store'), ['name' => '', 'slug' => 'admin', 'status' => 'active'])->assertOk();
        $response->assertSee('aria-describedby="name-error"', false)->assertSee('aria-describedby="slug-error"', false);
        $this->assertDescribedByTargetsExist($response);
    }

    public function test_the_helper_adds_hints_and_error_ids(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['tenant_ids' => ['Bitte wählen.']]));

        $this->assertSame('aria-invalid="true" aria-describedby="tenant_ids-error"', FieldErrorAttributes::render($errors, 'tenant_ids'));
        $this->assertSame('aria-invalid="true" aria-describedby="hint field-error"', FieldErrorAttributes::render($errors, 'tenant_ids', 'field', 'hint'));
        $this->assertSame('aria-describedby="hint"', FieldErrorAttributes::render($errors, 'other', 'field', 'hint'));
        $this->assertSame('', FieldErrorAttributes::render($errors, 'other'));
        $this->assertSame('', FieldErrorAttributes::render(null, 'tenant_ids'));
    }
}
