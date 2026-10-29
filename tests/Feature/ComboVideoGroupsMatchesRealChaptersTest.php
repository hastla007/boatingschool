<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Support\ComboVideoGroups;
use Tests\TestCase;

/**
 * Bug-Review (SBF See & Binnen: Videokapitel "Berufsschifffahrt" und
 * "Leuchtfeuer und Tonnen" fehlen): ComboVideoGroups::GROUPS listete nur
 * 7 von 9 echten SBF-SEE-Kapiteln für die "SBF See"-Fortschrittskachel
 * im Kombikurs -- die beiden fehlenden Kapitel waren über die Kachel
 * (?kapitel=<nur gelistete IDs>) nicht erreichbar und zählten nicht im
 * Fortschritts-Nenner, sodass die Kachel 100% zeigen konnte, obwohl
 * diese Kapitel nie angesehen wurden.
 */
class ComboVideoGroupsMatchesRealChaptersTest extends TestCase
{
    public function test_sbf_see_group_lists_every_real_sbf_see_chapter_except_the_standalone_tiles(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();

        $realChapters = $sbfSee->videoModules()->pluck('title');

        // "Knoten" hat eine eigene, von ComboVideoGroups unabhängige
        // Kachel/Seite (siehe CourseController::$knotenModule) und ist
        // hier bewusst ausgenommen.
        $expectedInGroup = $realChapters->reject(fn (string $title) => $title === 'Knoten');

        $groupChapters = collect(ComboVideoGroups::GROUPS['SBF-SEE-BIN'])
            ->firstWhere('label', 'SBF See')['chapters'];

        foreach ($expectedInGroup as $chapter) {
            $this->assertContains(
                $chapter,
                $groupChapters,
                "ComboVideoGroups fehlt das echte SBF-SEE-Kapitel \"{$chapter}\" -- im Kombikurs über die SBF-See-Kachel nicht erreichbar."
            );
        }
    }

    public function test_berufsschifffahrt_and_leuchtfeuer_chapters_are_explicitly_listed(): void
    {
        $groupChapters = collect(ComboVideoGroups::GROUPS['SBF-SEE-BIN'])
            ->firstWhere('label', 'SBF See')['chapters'];

        $this->assertContains('Berufsschifffahrt', $groupChapters);
        $this->assertContains('Leuchtfeuer und Tonnen', $groupChapters);
    }
}
