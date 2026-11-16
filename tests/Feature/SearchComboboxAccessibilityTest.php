<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Barrierefreiheit der Suchfelder (Combobox nach WAI-ARIA APG): Teilnehmer-
 * suche beim Zuweisen eines Zugangscodes, Verwaltersuche bei Lerngruppen und
 * die Schulauswahl im Superadmin. Geprüft wird das ausgelieferte Markup:
 * verknüpftes Label, Combobox-Rollen (per Alpine gesetzt), Listbox mit
 * Optionen, aria-activedescendant auf existierende Options-IDs, Live-Region
 * für Trefferzahl/„keine Treffer“ und ausreichender Kontrast der
 * Zweitzeile auf dem markierten Treffer (axe: color-contrast).
 */
class SearchComboboxAccessibilityTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        if ($this->createdCodes !== []) {
            $this->onAdmin(fn () => Coupon::whereIn('code', $this->createdCodes)->delete());
        }
        parent::tearDown();
    }

    public function test_assign_code_participant_search_is_an_accessible_combobox(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $code = 'E2E-A11Y-'.uniqid();
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $this->existingCourse('SRC')->id, 'tenant_id' => $tenant->id]));

        $html = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard', ['tab' => 'coupons']))->assertOk()->getContent();

        $this->assertParticipantCombobox($html, 'assign-email-'.$coupon->id, 'Teilnehmer für Zugangscode '.$code);
    }

    public function test_learning_group_manager_search_is_an_accessible_combobox(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $html = $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.create'))->assertOk()->getContent();

        $this->assertParticipantCombobox($html, 'owner_email', 'Verwalter (aktiver Teilnehmer)');
    }

    public function test_superadmin_tenant_picker_is_an_accessible_combobox(): void
    {
        $superadmin = $this->createSuperAdmin();

        foreach (['/superadmin/users/create' => 'Bootsschule', '/superadmin/coupons' => 'Bootsschule (optional)'] as $url => $label) {
            $html = $this->actingAs($superadmin)->get($url)->assertOk()->getContent();
            $id = 'tenant_id';

            $this->assertLabelFor($html, $id, $label);
            $input = $this->tagWithId($html, 'input', $id);
            $this->assertStringContainsString('x-bind:role="\'combobox\'"', $input);
            $this->assertStringContainsString('x-bind:aria-autocomplete="\'list\'"', $input);
            $this->assertStringContainsString('x-bind:aria-controls="\''.$id.'-listbox\'"', $input);
            $this->assertStringContainsString('x-bind:aria-expanded="open ? \'true\' : \'false\'"', $input);
            $this->assertStringContainsString('x-bind:aria-activedescendant="active >= 0 ? optionId(active) : null"', $input);
            $this->assertStringContainsString('aria-describedby="'.$id.'-hint"', $input);
            $this->assertStringContainsString('@keydown.escape=', $input);
            $this->assertStringContainsString('@keydown.enter=', $input);

            $listbox = $this->listbox($html, $id);
            $this->assertStringContainsString('aria-label="Gefundene Bootsschulen"', $listbox);
            // optionId(index) erzeugt dieselben IDs, auf die aria-activedescendant zeigt.
            $this->assertStringContainsString('<li x-bind:id="optionId(index)" role="option" x-bind:aria-selected="index === active ? \'true\' : \'false\'"', $listbox);
            $this->assertContrastOnActiveOption($listbox);

            $this->assertLiveRegionAfter($html, $id, 'data-tenant-search-status');
        }
    }

    private function assertParticipantCombobox(string $html, string $id, string $label): void
    {
        $this->assertLabelFor($html, $id, $label);
        $input = $this->tagWithId($html, 'input', $id);
        $this->assertStringContainsString('x-bind:role="\'combobox\'"', $input);
        $this->assertStringContainsString('x-bind:aria-autocomplete="\'list\'"', $input);
        $this->assertStringContainsString('x-bind:aria-controls="\''.$id.'-listbox\'"', $input);
        $this->assertStringContainsString('x-bind:aria-expanded="open ? \'true\' : \'false\'"', $input);
        $this->assertStringContainsString('x-bind:aria-activedescendant="active >= 0 ? \''.$id.'-option-\' + active : null"', $input);
        $this->assertStringContainsString('@keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"', $input);
        $this->assertStringContainsString('@keydown.escape=', $input);
        $this->assertStringContainsString('@keydown.enter=', $input);

        $listbox = $this->listbox($html, $id);
        $this->assertStringContainsString('aria-label="Gefundene Teilnehmer"', $listbox);
        $this->assertStringContainsString('<li x-bind:id="\''.$id.'-option-\' + index" role="option" x-bind:aria-selected="index === active ? \'true\' : \'false\'"', $listbox);
        $this->assertContrastOnActiveOption($listbox);

        $this->assertLiveRegionAfter($html, $id, 'data-participant-search-status');
    }

    private function assertLabelFor(string $html, string $id, string $label): void
    {
        $this->assertMatchesRegularExpression('/<label[^>]*for="'.preg_quote($id, '/').'"[^>]*>\s*'.preg_quote($label, '/').'\s*<\/label>/u', $html);
    }

    /** Das Textfeld bis zur Listbox (Alpine-Ausdrücke enthalten ">", daher kein [^>]*). */
    private function tagWithId(string $html, string $tag, string $id): string
    {
        $this->assertSame(1, preg_match('/<'.$tag.' id="'.preg_quote($id, '/').'".*?(?=<ul id="'.preg_quote($id, '/').'-listbox")/s', $html, $match), "<$tag id=\"$id\"> fehlt");

        return $match[0];
    }

    private function listbox(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<ul id="'.preg_quote($id, '/').'-listbox" role="listbox".*?<\/ul>/s', $html, $match), "Listbox $id-listbox fehlt");

        return $match[0];
    }

    /**
     * Der markierte Treffer hat bg-blue-50 bzw. dark:bg-slate-700; darauf
     * erreichen text-slate-500 (4,37:1) und dark:text-slate-400 (4,03:1)
     * die geforderten 4,5:1 für kleine Schrift nicht.
     */
    private function assertContrastOnActiveOption(string $listbox): void
    {
        $this->assertStringContainsString("x-bind:class=\"index === active ? 'bg-blue-50 dark:bg-slate-700' : ''\"", $listbox);
        $this->assertStringNotContainsString('text-slate-500', $listbox);
        $this->assertStringNotContainsString('dark:text-slate-400', $listbox);
        $this->assertStringContainsString('text-slate-600 dark:text-slate-300', $listbox);
    }

    private function assertLiveRegionAfter(string $html, string $id, string $marker): void
    {
        $start = strpos($html, '<ul id="'.$id.'-listbox"');
        $region = strpos($html, 'role="status" aria-live="polite" x-text="message" '.$marker, $start);
        $this->assertNotFalse($region, 'Live-Region für Trefferzahl fehlt');
        // Die Live-Region gehört zu diesem Feld: vor dem nächsten Suchfeld.
        $next = strpos($html, '<ul id="', $start + 1);
        $this->assertTrue($next === false || $region < $next);
    }
}
