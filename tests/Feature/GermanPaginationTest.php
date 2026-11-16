<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * Seitennavigation auf Deutsch (resources/views/vendor/pagination), auch
 * wenn APP_LOCALE nicht gesetzt ist.
 */
class GermanPaginationTest extends TestCase
{
    public function test_the_length_aware_pagination_is_german(): void
    {
        app()->setLocale('en');
        $html = (new LengthAwarePaginator(range(1, 30), 1011, 30, 2, ['path' => '/admin/audit']))->links()->toHtml();

        $this->assertStringContainsString('aria-label="Seitennavigation"', $html);
        $this->assertMatchesRegularExpression('/31<\/span>\s*bis\s*<span class="font-medium">60<\/span>\s*von\s*<span class="font-medium">1\.011<\/span>\s*Einträgen/', $html);
        $this->assertStringContainsString('aria-label="Seite 3"', $html);
        $this->assertStringContainsString('aria-label="Vorherige Seite"', $html);
        $this->assertStringContainsString('aria-label="Nächste Seite"', $html);
        $this->assertStringContainsString('&laquo; Zurück', $html);
        foreach (['Showing', 'results', 'Pagination Navigation', 'Go to page', 'Previous', 'Next'] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }
    }

    public function test_the_simple_pagination_is_german(): void
    {
        app()->setLocale('en');
        $html = (new Paginator(range(1, 31), 30, 2, ['path' => '/x']))->links()->toHtml();

        $this->assertStringContainsString('aria-label="Seitennavigation"', $html);
        $this->assertStringContainsString('Weiter &raquo;', $html);
        $this->assertStringNotContainsString('Previous', $html);
    }
}
