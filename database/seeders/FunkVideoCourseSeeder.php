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
 * Produktion zeigen alle Lektionen PLACEHOLDER_VIDEO. Die Kapitel sind
 * didaktisch gegliedert (Einführung, Technik, Routine-, Not-, Dringlichkeits-
 * und Sicherheitsverkehr, Prüfung); die Kapiteltitel sind über SRC und UBI
 * hinweg eindeutig, damit ComboVideoGroups sie dem richtigen Teilkurs
 * zuordnen kann.
 */
class FunkVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapitel => [[Lektion, Dauer in Sekunden], ...]. */
    public const SRC_CHAPTERS = [
        'Einführung in das SRC' => [
            ['Willkommen zum Short Range Certificate', 142],
            ['GMDSS: das weltweite Seenot- und Sicherheitsfunksystem', 218],
            ['Seegebiete A1 bis A4', 164],
            ['Funkzeugnisse und Zuteilungsurkunde', 171],
            ['Warum das Handy auf See nicht reicht', 133],
        ],
        'Technik und Funkgerät' => [
            ['UKW-Seefunk: Reichweite und Ausbreitung', 186],
            ['Kanäle und Frequenzen', 177],
            ['Kanal 16, 70 und Arbeitskanäle', 159],
            ['Bedienelemente der UKW-Seefunkanlage', 224],
            ['Antenne, Stromversorgung und Sendeleistung', 168],
            ['MMSI und der DSC-Controller', 203],
        ],
        'Routineverkehr im Alltag' => [
            ['Sprechregeln und Buchstabiertafel', 192],
            ['Rechtliche Regeln im Seefunk', 154],
            ['DSC-Routineanruf von Schiff zu Schiff', 211],
            ['Revier- und Hafenfunk', 166],
            ['Funkprobe und Testanruf', 128],
        ],
        'Notverkehr – MAYDAY' => [
            ['Wann ist es ein Notfall?', 147],
            ['DSC-Notalarm auslösen', 196],
            ['Die Notmeldung Schritt für Schritt', 238],
            ['Notverkehr bestätigen und weiterleiten (MAYDAY RELAY)', 221],
            ['Fehlalarm richtig aufheben', 152],
            ['EPIRB, SART und AIS-SART', 207],
        ],
        'Dringlichkeit und Sicherheit – PAN PAN und SECURITE' => [
            ['Dringlichkeitsmeldung (PAN PAN)', 189],
            ['Funkärztliche Beratung (MEDICO)', 163],
            ['Sicherheitsmeldung (SECURITE)', 174],
            ['NAVTEX: Warnungen empfangen und auswerten', 181],
        ],
        'Prüfung und Vorbereitung' => [
            ['Ablauf der SRC-Prüfung', 158],
            ['Praktische Prüfung am Funkgerät', 213],
            ['Englische Seefunktexte und Vokabeln', 176],
        ],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapiteltitel eindeutig gegenüber SRC_CHAPTERS. */
    public const UBI_CHAPTERS = [
        'Einführung in das UBI' => [
            ['Willkommen zum UKW-Sprechfunkzeugnis für den Binnenschifffahrtsfunk', 139],
            ['Rechtliche Grundlagen: RAINWAT und Binnenschifffahrtsfunk', 187],
            ['Funkzeugnisse und Frequenzzuteilung', 162],
        ],
        'Funkanlage im Binnenfunk' => [
            ['Die UKW-Funkanlage an Bord', 193],
            ['ATIS: die automatische Senderkennung', 171],
            ['Sendeleistung: 1 Watt und 25 Watt', 148],
        ],
        'Verkehrskreise und Kanäle' => [
            ['Die Verkehrskreise im Überblick', 204],
            ['Schiff – Schiff', 157],
            ['Nautische Information', 166],
            ['Schiff – Hafenbehörde', 151],
            ['Funkverkehr an Bord', 134],
        ],
        'Sprechfunk im Alltag' => [
            ['Sprechregeln und Buchstabiertafel im Binnenfunk', 182],
            ['Anruf, Antwort und Gesprächsende', 174],
            ['Textaufnahme und Textabgabe üben', 168],
        ],
        'Notverkehr im Binnenfunk' => [
            ['Wann ist es ein Notfall?', 143],
            ['Notmeldung absetzen (MAYDAY)', 216],
            ['Notverkehr bestätigen und weiterleiten', 189],
        ],
        'Dringlichkeit und Sicherheit im Binnenfunk' => [
            ['Dringlichkeitsmeldung (PAN PAN)', 172],
            ['Sicherheitsmeldung (SECURITE)', 161],
            ['Rangfolge der Meldungen', 137],
        ],
        'UBI-Prüfung und Vorbereitung' => [
            ['Ablauf der UBI-Prüfung', 149],
            ['Typische Fehler in der praktischen Prüfung', 163],
        ],
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
