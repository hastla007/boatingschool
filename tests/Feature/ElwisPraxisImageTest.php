<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Database\Seeders\ElwisPraxisImageSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Praxistrainer-Karten mit ELWIS-Gegenstück zeigen das ELWIS-Bild der
 * Katalogfrage (siehe ElwisPraxisImageSeeder).
 */
class ElwisPraxisImageTest extends TestCase
{
    use InteractsWithTenants;

    public function test_mapped_cards_use_the_elwis_image_in_both_courses(): void
    {
        $this->onAdmin(function () {
            foreach (['PRAXIS-0035', 'PRAXIS-0035-SEE-BIN'] as $contentId) {
                $task = PraxisTask::where('content_id', $contentId)->with('media')->firstOrFail();
                $this->assertSame('elwis/sbf_basis_017_1.png', $task->media->asset_key, $contentId);
            }

            $this->assertSame('elwis/sbf_see_pdfbild_077.png', PraxisTask::where('content_id', 'PRAXIS-0049')->firstOrFail()->media->asset_key);
            $this->assertSame(146, PraxisTask::whereHas('media', fn ($q) => $q->where('asset_key', 'like', 'elwis/%'))->count());
        });
    }

    public function test_cards_without_an_elwis_counterpart_keep_their_own_image(): void
    {
        $this->onAdmin(function () {
            // Knoten und die nur ähnlichen Karten (0047: ELWIS zeigt Tafeln statt Lichter).
            foreach (['PRAXIS-0013', 'PRAXIS-0047'] as $contentId) {
                $task = PraxisTask::where('content_id', $contentId)->firstOrFail();
                $this->assertSame('SBF See Praxistrainer', $task->media->source, $contentId);
            }
        });
    }

    public function test_no_replaced_card_graphic_is_left_behind(): void
    {
        $this->onAdmin(function () {
            $unused = MediaAsset::where('source', 'SBF See Praxistrainer')
                ->whereNotIn('id', PraxisTask::whereNotNull('media_asset_id')->select('media_asset_id'))
                ->count();

            $this->assertSame(0, $unused);
            $this->assertSame(68, MediaAsset::where('source', 'SBF See Praxistrainer')->count());
            $this->assertFileDoesNotExist(database_path('data/praxistrainer_bilder/praxis-0035_zeichen_auf_see.png'));
        });
    }

    public function test_redrawn_card_graphics_replace_an_outdated_stored_file(): void
    {
        $path = 'question-media/praxistrainer/praxis-0013_seemannsknoten.png';
        $current = file_get_contents(database_path('data/praxistrainer_bilder/praxis-0013_seemannsknoten.png'));

        $this->onAdmin(function () use ($path, $current) {
            Storage::disk('public')->put($path, 'alte dunkelblaue Grafik');
            (new ElwisPraxisImageSeeder)->run();

            $this->assertSame($current, Storage::disk('public')->get($path));
        });
    }

    public function test_redrawn_graphics_have_svg_sources(): void
    {
        foreach (glob(database_path('data/praxistrainer_bilder/*.png')) as $png) {
            $this->assertFileExists(database_path('data/praxistrainer_bilder_svg/'.basename($png, '.png').'.svg'));
            $this->assertSame([1200, 800], array_slice(getimagesize($png), 0, 2), basename($png));
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->onAdmin(function () {
            $before = PraxisTask::orderBy('content_id')->pluck('media_asset_id', 'content_id')->all();
            (new ElwisPraxisImageSeeder)->run();

            $this->assertSame($before, PraxisTask::orderBy('content_id')->pluck('media_asset_id', 'content_id')->all());
        });
    }
}
