<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Bug-Review (Y7: Dunkelmodus -- grauer Text zu schwach an rund 75 Stellen):
 * zwei verschiedene Ursachen fanden sich beim Kontrast-Audit (automatisiert
 * im Browser gegen den jeweils effektiven Hintergrund gemessen, siehe
 * Session-Notizen):
 *
 * 1. An 12 Stellen (Footer, Admin-Dashboard, Kursseiten, Angebote, Wartungs-
 *    seite) stand sowohl "dark:text-slate-400" als auch ein überflüssiges,
 *    nachfolgendes "dark:text-slate-500" auf demselben Element -- die später
 *    im Tailwind-Stylesheet erzeugte Utility (slate-500) gewinnt die Kaskade
 *    und überschreibt die eigentlich sichere slate-400-Farbe (5,7:1 gegen
 *    dunkle Kartenhintergründe) mit der zu schwachen slate-500-Farbe
 *    (3,1-3,8:1, unter den von WCAG AA verlangten 4,5:1).
 * 2. An rund 170 weiteren Stellen (Admin-/Superadmin-Tabs, Lernseiten,
 *    Prüfung, Video-Quiz) wurde "text-slate-500" ganz ohne dunkleres
 *    Dark-Mode-Gegenstück verwendet -- im Hellmodus gegen Weiß noch
 *    knapp ausreichend (4,8:1), im Dunkelmodus gegen die dunklen
 *    Kartenhintergründe aber zu schwach (3,1-3,8:1).
 *
 * Die Fragen zum SRC/UBI-Video-Quiz aus Y6 betreffen dieselbe Baustelle
 * nicht -- siehe FknSknLessonQuestionSeeder-Fix.
 */
class DarkModeContrastRegressionTest extends TestCase
{
    public function test_no_view_combines_dark_text_slate_400_with_a_later_dark_text_slate_500_on_the_same_element(): void
    {
        $finder = new Finder;
        $finder->files()->in(resource_path('views'))->name('*.blade.php');

        $offenders = [];

        foreach ($finder as $file) {
            if (preg_match('/dark:text-slate-400[^"\']*dark:text-slate-500/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'dark:text-slate-500 überschreibt das sichere dark:text-slate-400 auf demselben Element in: '.implode(', ', $offenders)
        );
    }

    public function test_no_view_uses_bare_slate_500_text_without_a_dark_mode_counterpart(): void
    {
        $finder = new Finder;
        $finder->files()->in(resource_path('views'))->name('*.blade.php');

        $offenders = [];

        foreach ($finder as $file) {
            $content = $file->getContents();

            foreach (preg_split('/\R/', $content) as $line) {
                if (str_contains($line, 'classList.toggle')) {
                    // JS-Toggle wird separat über eine begleitende
                    // dark:text-slate-400-Toggle-Zeile abgedeckt.
                    continue;
                }

                if (preg_match('/(?<![\w:-])text-slate-500(?![\w-])/', $line) && ! str_contains($line, 'dark:text-')) {
                    $offenders[] = $file->getRelativePathname();

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Bare text-slate-500 (ohne dark:-Gegenstück) gefunden in: '.implode(', ', $offenders)
        );
    }
}
