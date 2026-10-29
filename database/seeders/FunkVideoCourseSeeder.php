<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurse für die Funkscheine: SRC, UBI, die UBI-Ergänzung für
 * SRC-Inhaber und der Kombikurs SRC & UBI (Teilkurs-Kacheln über
 * App\Support\ComboVideoGroups). Wie bei FknSknVideoCourseSeeder eine
 * gemeinsame Videobibliothek, die je Kurs in eigenen
 * video_module/video_lesson-Datensätzen eingesetzt wird; bis zur
 * Produktion zeigen alle Lektionen PLACEHOLDER_VIDEO. Die Kapitel folgen
 * den Kapiteln der Fragenkataloge (smartmodus_kategorie), die Kapiteltitel
 * sind über SRC und UBI hinweg eindeutig, damit ComboVideoGroups sie dem
 * richtigen Teilkurs zuordnen kann.
 */
class FunkVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapitel => [[Lektion, Dauer in Sekunden], ...]. */
    public const SRC_CHAPTERS = [
        'GMDSS und Seefunkdienst' => [['Das GMDSS im Überblick', 184], ['Seegebiete A1 bis A4', 152]],
        'Funkeinrichtungen an Bord' => [['UKW-Seefunkanlage und Bedienelemente', 211], ['Antennen, Reichweite und Stromversorgung', 166]],
        'UKW-Sprechfunk' => [['Kanäle und Betriebsarten', 173], ['Buchstabiertafel und Sprechregeln', 158]],
        'DSC – Digitaler Selektivruf' => [['MMSI und DSC-Anrufarten', 189], ['DSC-Alarm auslösen und bestätigen', 204]],
        'Betriebsverfahren im Seefunk' => [['Routineverkehr', 161], ['Dringlichkeits- und Sicherheitsverkehr', 177]],
        'Seenotfunk: SAR, EPIRB und SART' => [['Notverkehr und MAYDAY-Meldung', 226], ['EPIRB, SART und Rettungsmittel', 193]],
        'NAVTEX' => [['NAVTEX empfangen und auswerten', 149]],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> */
    public const UBI_CHAPTERS = [
        'Binnenschifffahrtsfunk' => [['Rechtliche Grundlagen und Funkzeugnisse', 171]],
        'Funkeinrichtungen und Schiffsfunkstellen' => [['Funkanlage, ATIS und Sendeleistung', 186]],
        'Verkehrskreise' => [['Die Verkehrskreise im Überblick', 198]],
        'Sprechfunk' => [['Anrufe und Sprechfunkverfahren', 179]],
        'Betriebsverfahren und Rangfolgen' => [['Notverkehr, Dringlichkeit und Sicherheit', 207]],
    ];

    public function run(): void
    {
        $this->buildCourse('SRC', self::SRC_CHAPTERS);
        $this->buildCourse('UBI', self::UBI_CHAPTERS);
        $this->buildCourse('ADD-UBI-TO-SRC', self::UBI_CHAPTERS);
        $this->buildCourse('SRC-UBI', self::SRC_CHAPTERS + self::UBI_CHAPTERS);
    }

    /** @param array<string, array<int, array{0: string, 1: int}>> $chapters */
    private function buildCourse(string $courseCode, array $chapters): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapterIndex = 0;
        foreach ($chapters as $chapterTitle => $lessons) {
            $module = VideoModule::create([
                'course_id' => $course->id,
                'title' => $chapterTitle,
                'sort_order' => ++$chapterIndex,
            ]);

            foreach ($lessons as $lessonIndex => [$title, $duration]) {
                VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $title,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => $lessonIndex + 1,
                ]);
            }
        }
    }
}
