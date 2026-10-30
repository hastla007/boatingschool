<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (rohe Feldnamen in deutschen Fehlermeldungen):
 * lang/de/validation.php kannte nur rund 30 Feldnamen -- für alle anderen
 * in Admin-/Superadmin-Controllern validierten Felder bildete Laravel den
 * Text aus dem Feldnamen ("praxis ort muss ausgefüllt werden", "site name
 * ...", "quantity ...").
 */
class AdminAndSuperadminValidationAttributesAreGermanTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_every_field_validated_in_admin_and_superadmin_controllers_has_a_german_label(): void
    {
        $fields = [
            'action', 'active', 'addons.*.coupon_code', 'addons.*.discount_percent', 'addons.*.offer_url',
            'addons.*.trigger_percent', 'answers.A', 'badge_text', 'batch_label', 'body', 'bullets',
            'category_id', 'competency', 'contact_first_name', 'contact_last_name', 'correct', 'coupon_code',
            'course_ids', 'course_type', 'course_validity_days', 'cta_label', 'description', 'discount_percent',
            'duration', 'ends_at', 'exam_date', 'exam_readiness_threshold_percent', 'favicon', 'fax',
            'feedback_correct', 'feedback_incorrect', 'fields.*.key', 'fields.*.label', 'headline', 'hinweis',
            'ids', 'kommission', 'kontaktperson', 'legal_name', 'links.*', 'location', 'maintenance_message',
            'min_progress_percent', 'model_answer', 'module_id', 'multi_answers.*.text', 'offer_url',
            'official_number', 'online_meeting_url', 'option_product_ids', 'pdf', 'plz',
            'praxis_ort', 'praxis_strasse', 'praxis_plz', 'praxis_stadt', 'praxis_land', 'praxis_lat', 'praxis_lng',
            'pruefung_ort', 'pruefung_lat', 'pruefung_lng', 'theorie_ort', 'theorie_lat', 'theorie_lng',
            'price_label', 'primary_color', 'pruefungsausschuss_id', 'quantity', 'question_id', 'question_text',
            'question_type', 'renewal_webshop_link', 'requires_tauglichkeit', 'secondary_color', 'site_name',
            'smartmodus_kategorie', 'source_catalog', 'sprechzeiten', 'stadt', 'standorttyp', 'stat_text',
            'strasse', 'subtopic', 'target', 'telefon', 'topic', 'tracking_number', 'trigger_percent', 'user_id',
            'valid_from', 'valid_to', 'version', 'video_url', 'whatsapp_greeting', 'whatsapp_phone',
            'willkommensbox_threshold_percent',
        ];

        $attributes = trans('validation.attributes', [], 'de');

        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $attributes, "Kein deutsches Label für das Feld {$field}.");
            $this->assertNotSame($field, $attributes[$field]);
        }

        $this->assertSame('Praxis-Ort', $attributes['praxis_ort']);
        $this->assertSame('Praxis-Breitengrad', $attributes['praxis_lat']);
        $this->assertSame('Praxis-Längengrad', $attributes['praxis_lng']);
    }

    public function test_creating_a_location_without_a_praxis_ort_shows_german_field_names(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->post(route('admin.tenant-course-locations.store'), [
            'praxis_ort' => '',
            'praxis_lat' => 'nördlich',
            'praxis_lng' => '500',
        ]);

        $response->assertSessionHasErrors(['praxis_ort', 'praxis_lat', 'praxis_lng']);
        $errors = session('errors');
        $this->assertStringContainsString('Praxis-Ort', $errors->first('praxis_ort'));
        $this->assertStringNotContainsString('praxis ort', $errors->first('praxis_ort'));
        $this->assertStringContainsString('Praxis-Breitengrad', $errors->first('praxis_lat'));
        $this->assertStringContainsString('Praxis-Längengrad', $errors->first('praxis_lng'));
    }

    public function test_superadmin_forms_show_german_field_names(): void
    {
        $superadmin = $this->createSuperAdmin();

        $settings = $this->actingAs($superadmin)->patch(route('superadmin.settings.update'), ['site_name' => '']);
        $settings->assertSessionHasErrors('site_name');
        $this->assertStringContainsString('Name der Plattform', session('errors')->first('site_name'));
        $this->assertStringNotContainsString('site name', session('errors')->first('site_name'));

        $coupons = $this->actingAs($superadmin)->post(route('superadmin.coupons.store'), ['target' => '', 'quantity' => '']);
        $coupons->assertSessionHasErrors(['target', 'quantity']);
        $this->assertStringContainsString('Kurs oder Produkt', session('errors')->first('target'));
        $this->assertStringContainsString('Anzahl', session('errors')->first('quantity'));
        $this->assertStringNotContainsString('quantity', session('errors')->first('quantity'));
    }
}
