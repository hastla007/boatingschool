<?php

namespace Tests\Feature\Api;

use App\Models\CustomerFaq;
use App\Models\CustomerFaqCategory;
use App\Models\PlatformSetting;
use App\Models\TenantUser;
use App\Models\Tip;
use App\Models\TipCategory;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Services\PersonalDataExportService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mobile-API: Health, synchronisierte App-Einstellungen, Tipps und Kunden-FAQs. */
class AccountSettingsTipsAndFaqApiTest extends MobileApiTestCase
{
    private array $tipCategoryIds = [];

    private array $faqSourcePrefixes = [];

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            Tip::whereIn('category_id', $this->tipCategoryIds)->delete();
            TipCategory::whereIn('id', $this->tipCategoryIds)->delete();
            foreach ($this->faqSourcePrefixes as $prefix) {
                CustomerFaq::where('source_key', 'like', $prefix.'%')->delete();
                CustomerFaqCategory::where('source_key', 'like', $prefix.'%')->delete();
            }
            foreach ($this->createdUserIds as $id) {
                DB::table('api_user_setting')->where('user_id', $id)->delete();
            }
        });
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_health_is_public_and_stays_reachable_during_maintenance(): void
    {
        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('data.status', 'ok')->assertJsonPath('data.api_version', 'v1')
            ->assertJsonStructure(['data' => ['server_time']]);

        $before = $this->onAdmin(fn () => PlatformSetting::query()->first())?->maintenance_mode;
        try {
            $this->onAdmin(fn () => DB::table('platform_setting')->update(['maintenance_mode' => true]));
            $this->getJson('/api/v1/health')->assertOk();
        } finally {
            $this->onAdmin(fn () => DB::table('platform_setting')->update(['maintenance_mode' => (bool) $before]));
        }

        // Zu alte App-Version: Health antwortet trotzdem.
        config(['mobile_api.minimum_version.ios' => '9.0.0']);
        $this->getJson('/api/v1/health', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.0.0'])->assertOk();
    }

    public function test_settings_are_versioned_per_account_and_protected_against_lost_updates(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        $empty = $this->api('GET', '/me/settings', $token)->assertOk()
            ->assertHeader('ETag', '"0"')->assertJsonPath('data.version', 0)->assertJsonPath('data.updated_at', null);
        $this->assertStringContainsString('"settings":{}', $empty->getContent());

        $settings = ['theme' => 'dark', 'trainer' => ['sound' => false, 'daily_goal' => 20], 'note' => '  mit Leerzeichen  ', 'empty' => ''];
        $this->api('PUT', '/me/settings', $token, null, ['settings' => $settings], ['If-Match' => '"0"'])->assertOk()
            ->assertHeader('ETag', '"1"')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.settings.trainer.daily_goal', 20)
            // Texte bleiben unverändert (kein Trim, kein leer -> null).
            ->assertJsonPath('data.settings.note', '  mit Leerzeichen  ')
            ->assertJsonPath('data.settings.empty', '');

        // Anderes Gerät mit veraltetem Stand: Konflikt samt aktuellem Stand.
        $this->api('PUT', '/me/settings', $token, null, ['settings' => ['theme' => 'light']], ['If-Match' => '"0"'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT')
            ->assertJsonPath('details.current.version', 1)->assertJsonPath('details.current.settings.theme', 'dark');
        $this->api('PUT', '/me/settings', $token, null, ['settings' => ['theme' => 'light'], 'expected_version' => 0])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        // Widersprüchliche Angaben bzw. falsches ETag-Format.
        $this->api('PUT', '/me/settings', $token, null, ['settings' => [], 'expected_version' => 2], ['If-Match' => '"1"'])->assertStatus(422);
        $this->api('PUT', '/me/settings', $token, null, ['settings' => []], ['If-Match' => '1'])->assertStatus(422);

        // Ohne Versionsangabe: einfach ersetzen.
        $this->api('PUT', '/me/settings', $token, null, ['settings' => ['theme' => 'light'], 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.settings', ['theme' => 'light']);
        $this->api('GET', '/me/settings', $token)->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.settings.theme', 'light');

        // Leeres Objekt setzt zurück und bleibt ein Objekt.
        $reset = $this->api('PUT', '/me/settings', $token, null, ['settings' => new \stdClass])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertStringContainsString('"settings":{}', $reset->getContent());

        // Andere Konten sehen nichts davon.
        $this->api('GET', '/me/settings', $other)->assertOk()->assertJsonPath('data.version', 0);
        // Ohne Anmeldung kein Zugriff.
        $this->getJson('/api/v1/me/settings')->assertStatus(401);
    }

    public function test_settings_reject_invalid_shapes(): void
    {
        $tenant = $this->createTestTenant();
        $token = $this->apiLogin($this->createTenantUser($tenant, 'learner'));

        $invalid = [
            'fehlt' => [],
            'kein Objekt' => ['settings' => 'dark'],
            'Liste statt Objekt' => ['settings' => ['a', 'b']],
            'Großbuchstaben im Schlüssel' => ['settings' => ['Theme' => 'dark']],
            'zu tief' => ['settings' => ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]],
            'zu groß' => ['settings' => ['blob' => str_repeat('x', 8200)]],
        ];
        foreach ($invalid as $case => $body) {
            $this->api('PUT', '/me/settings', $token, null, $body)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        }
        // Listen als Werte und vier Ebenen sind erlaubt.
        $this->api('PUT', '/me/settings', $token, null, ['settings' => ['a' => ['b' => ['c' => ['d' => 1]]], 'tags' => ['x', 'y']]])->assertOk();
    }

    public function test_settings_are_part_of_the_data_export_and_removed_with_the_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $this->api('PUT', '/me/settings', $token, null, ['settings' => ['theme' => 'dark']])->assertOk();

        $export = $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export(User::findOrFail($user->id), $tenant));
        $this->assertSame(['theme' => 'dark'], $export['app_settings']['settings']);
        $this->assertSame(1, $export['app_settings']['version']);
        // Export an die Schule: kontoweite Einstellungen gehören nicht dazu.
        $forSchool = $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export(User::findOrFail($user->id), $tenant, true));
        $this->assertArrayNotHasKey('app_settings', $forSchool);

        $this->asTenant($tenant, function () use ($tenant, $user) {
            app(TenantContext::class)->set($tenant);
            app(AccountErasureService::class)->leaveTenant(User::findOrFail($user->id), $tenant);
        });
        $this->assertFalse($this->onAdmin(fn () => DB::table('api_user_setting')->where('user_id', $user->id)->exists()));
    }

    public function test_leaving_one_school_keeps_account_wide_settings(): void
    {
        $tenant = $this->createTestTenant();
        $second = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $second->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $this->api('PUT', '/me/settings', $user, null, ['settings' => ['theme' => 'dark']])->assertOk();

        $this->asTenant($tenant, fn () => app(AccountErasureService::class)->leaveTenant(User::findOrFail($user->id), $tenant));
        $this->assertTrue($this->onAdmin(fn () => DB::table('api_user_setting')->where('user_id', $user->id)->exists()));
    }

    public function test_tips_are_grouped_sanitized_and_need_a_school_context(): void
    {
        $tenant = $this->createTestTenant();
        $token = $this->apiLogin($this->createTenantUser($tenant, 'learner'));
        $name = 'Testtipps '.Str::random(6);
        $category = $this->onAdmin(fn () => TipCategory::create(['name' => $name, 'sort_order' => 999]));
        $empty = $this->onAdmin(fn () => TipCategory::create(['name' => 'Leer '.Str::random(6), 'sort_order' => 999]));
        $this->tipCategoryIds = [$category->id, $empty->id];
        $this->onAdmin(function () use ($category, $empty) {
            Tip::create(['category_id' => $category->id, 'title' => 'Knoten &amp; Leinen', 'sort_order' => 1, 'active' => true,
                'body' => '<p>Erst <a href="/tipps-tricks">hier</a> lesen, dann <a href="https://example.org/x">dort</a>.</p><script>alert(1)</script><img src="javascript:alert(1)"><ul><li>Eins</li></ul>']);
            Tip::create(['category_id' => $category->id, 'title' => 'Inaktiv', 'body' => 'x', 'sort_order' => 2, 'active' => false]);
            Tip::create(['category_id' => $empty->id, 'title' => 'Auch inaktiv', 'body' => 'x', 'sort_order' => 1, 'active' => false]);
        });

        $this->api('GET', '/tips', $token)->assertStatus(422);
        $response = $this->api('GET', '/tips', $token, $tenant)->assertOk();
        $groups = collect($response->json('data'));
        $this->assertFalse($groups->contains('id', $empty->id), 'Kategorien ohne aktive Tipps entfallen');
        $group = $groups->firstWhere('id', $category->id);
        $this->assertSame(Str::slug($name), $group['slug']);
        $this->assertCount(1, $group['tips']);
        $tip = $group['tips'][0];
        $this->assertSame('Knoten & Leinen', $tip['title']);
        $this->assertStringNotContainsString('script', $tip['html']);
        $this->assertStringNotContainsString('javascript:', $tip['html']);
        $this->assertStringContainsString('href="'.url('/tipps-tricks').'"', $tip['html']);
        $this->assertStringContainsString('• Eins', $tip['text']);
        $this->assertSame([['url' => url('/tipps-tricks'), 'text' => 'hier'], ['url' => 'https://example.org/x', 'text' => 'dort']], $tip['links']);
        $this->assertSame($groups->sum(fn ($g) => count($g['tips'])), $response->json('meta.tips'));
    }

    public function test_course_faqs_need_access_include_related_courses_and_are_sanitized(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $token = $this->apiLogin($user);
        $prefix = 'test:'.Str::random(8).':';
        $this->faqSourcePrefixes[] = $prefix;

        $this->onAdmin(function () use ($prefix) {
            $root = CustomerFaqCategory::create(['source_key' => $prefix.'root', 'course_code' => 'SRC-UBI', 'name' => 'Allgemein', 'slug' => $prefix.'allgemein', 'sort_order' => 1]);
            $child = CustomerFaqCategory::create(['source_key' => $prefix.'child', 'course_code' => 'SRC-UBI', 'parent_id' => $root->id, 'name' => 'Prüfung', 'slug' => $prefix.'pruefung', 'sort_order' => 1]);
            $ubi = CustomerFaqCategory::create(['source_key' => $prefix.'ubi', 'course_code' => 'UBI', 'name' => 'UBI', 'slug' => $prefix.'ubi', 'sort_order' => 1]);
            $other = CustomerFaqCategory::create(['source_key' => $prefix.'other', 'course_code' => 'SBF-SEE', 'name' => 'See', 'slug' => $prefix.'see', 'sort_order' => 1]);
            $shared = CustomerFaq::create(['source_key' => $prefix.'shared', 'question' => 'Wie lange dauert es?', 'answer' => '<p>Kurz. <a href="https://example.org">Mehr</a></p><script>x()</script><a href="javascript:x()">böse</a>', 'status' => 'published']);
            $shared->categories()->sync([$root->id => ['sort_order' => 1], $ubi->id => ['sort_order' => 1]]);
            CustomerFaq::create(['source_key' => $prefix.'child-faq', 'question' => 'Was wird geprüft?', 'answer' => 'Funk.', 'status' => 'published'])->categories()->sync([$child->id]);
            CustomerFaq::create(['source_key' => $prefix.'ubi-faq', 'question' => 'UBI?', 'answer' => 'Binnen.', 'status' => 'published'])->categories()->sync([$ubi->id]);
            CustomerFaq::create(['source_key' => $prefix.'draft', 'question' => 'Entwurf', 'answer' => 'x', 'status' => 'draft'])->categories()->sync([$root->id]);
            CustomerFaq::create(['source_key' => $prefix.'see', 'question' => 'See?', 'answer' => 'x', 'status' => 'published'])->categories()->sync([$other->id]);
        });

        $this->api('GET', "/courses/{$course->id}/faqs", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->grantEntitlement($tenant, $user, $course);

        $response = $this->api('GET', "/courses/{$course->id}/faqs", $token, $tenant)->assertOk()
            ->assertJsonPath('meta.course_id', $course->id);
        $groups = collect($response->json('data'))->filter(fn ($g) => str_starts_with($g['slug'], $prefix))->values();
        // Eigener Kurs zuerst, dann der enthaltene Kurs; fremde Kurse nicht.
        $this->assertSame(['SRC-UBI', 'UBI'], $groups->pluck('key')->all());
        $root = $groups[0];
        $this->assertSame(['Wie lange dauert es?'], array_column($root['faqs'], 'question'));
        $this->assertSame('Prüfung', $root['subcategories'][0]['name']);
        $this->assertSame(1, $root['subcategories'][0]['depth']);
        $this->assertSame($root['id'], $root['subcategories'][0]['parent_id']);
        // Mehrfach zugeordnete FAQ nur einmal; Entwurf nie.
        $this->assertSame(['UBI?'], array_column($groups[1]['faqs'], 'question'));
        $faq = $root['faqs'][0];
        $this->assertStringNotContainsString('script', $faq['answer_html']);
        $this->assertStringNotContainsString('javascript:', $faq['answer_html']);
        $this->assertSame([['url' => 'https://example.org', 'text' => 'Mehr']], $faq['links']);
        $this->assertStringStartsWith('Kurz.', $faq['answer_text']);
    }

    public function test_faq_import_is_validated_idempotent_and_app_role_is_read_only(): void
    {
        $prefix = 'test:'.Str::random(8).':';
        $this->faqSourcePrefixes[] = $prefix;
        $document = [
            'categories' => [
                ['source_key' => $prefix.'c1', 'course_code' => 'SKN', 'name' => 'Allgemein', 'slug' => $prefix.'allgemein'],
                ['source_key' => $prefix.'c2', 'course_code' => 'SKN', 'name' => 'Praxis', 'slug' => $prefix.'praxis', 'parent_source_key' => $prefix.'c1', 'sort_order' => 2],
            ],
            'faqs' => [
                ['source_key' => $prefix.'f1', 'question' => 'Frage 1', 'answer' => '<p>Antwort</p>', 'category_source_keys' => [$prefix.'c1', $prefix.'c2']],
            ],
        ];
        $path = $this->writeTempJson($document);

        $this->artisan('mobile-api:import-faqs', ['path' => $path])->assertSuccessful();
        $document['faqs'][0]['question'] = 'Frage 1 (neu)';
        $this->artisan('mobile-api:import-faqs', ['path' => $this->writeTempJson($document)])->assertSuccessful();
        $this->assertSame('pgsql', config('database.default'), 'Import stellt die Verbindung zurück');

        $this->onAdmin(function () use ($prefix) {
            $faq = CustomerFaq::where('source_key', $prefix.'f1')->firstOrFail();
            $this->assertSame('Frage 1 (neu)', $faq->question);
            $this->assertSame('published', $faq->status);
            $this->assertCount(2, $faq->categories);
            $this->assertSame(1, CustomerFaq::where('source_key', 'like', $prefix.'%')->count());
            $child = CustomerFaqCategory::where('source_key', $prefix.'c2')->firstOrFail();
            $this->assertSame(CustomerFaqCategory::where('source_key', $prefix.'c1')->value('id'), $child->parent_id);
        });

        // Ungültige Datei: nichts wird geändert.
        $broken = $document;
        $broken['faqs'][0]['question'] = 'Darf nicht ankommen';
        $broken['faqs'][] = ['source_key' => $prefix.'f2', 'question' => 'Ohne Kategorie', 'answer' => 'x', 'category_source_keys' => [$prefix.'fehlt']];
        $this->artisan('mobile-api:import-faqs', ['path' => $this->writeTempJson($broken)])->assertFailed();
        $this->assertSame('Frage 1 (neu)', $this->onAdmin(fn () => CustomerFaq::where('source_key', $prefix.'f1')->value('question')));

        // Die App-Rolle darf die FAQ-Tabellen nur lesen.
        $tenant = $this->createTestTenant();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table customer_faq/');
        $this->asTenant($tenant, fn () => DB::table('customer_faq')->insert([
            'id' => (string) Str::uuid(), 'source_key' => $prefix.'hack', 'question' => 'x', 'answer' => 'x', 'status' => 'published',
        ]));
    }

    private function writeTempJson(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'faq-import-');
        file_put_contents($path, json_encode($document));
        $this->tempFiles[] = $path;

        return $path;
    }
}
