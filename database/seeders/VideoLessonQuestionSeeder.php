<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz (siehe video_lesson_question,
 * VideoLessonController/VideoQuizController): verknüpft ausgewählte, bereits
 * veröffentlichte Fragen aus der zentralen Fragenbasis mit einzelnen
 * Videolektionen, passend zum jeweiligen Lektionsinhalt.
 */
class VideoLessonQuestionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> Kapitel => Lektion => Fragetexte, je Teilkurs. */
    private const LESSON_QUESTIONS = [
        'Einführung in den Sportbootführerschein' => [
            'Motor, Bootsführer und Fahrer' => [
                'Was ist zu tun, wenn vor Antritt der Fahrt nicht feststeht, wer Schiffsführer ist?',
                'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
                'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
                'Welche Sportboote sind von der Fahrerlaubnispflicht auf den Seeschifffahrtsstraßen ausgenommen?',
            ],
            'Motorbootfahren' => [
                'Welche Gefahren können entstehen, wenn ein kleines von einem größeren Fahrzeug überholt wird?',
                'Welches ist der günstigste Anlaufwinkel beim Anlegen?',
                'Wodurch wird bei einem Fahrzeug mit Außenbordmotor und ohne Ruderanlage die Ruderwirkung erzielt?',
                'Während der Fahrt sollte die Maschinenanlage ständig überwacht werden. Worauf muss besonders geachtet werden?',
                'Die Temperatur der Antriebsmaschine überschreitet die zulässigen Grenzwerte. Was könnte die mögliche Ursache sein?',
                'Die Ladekontrolllampe erlischt nach dem Starten nicht. Was könnte die mögliche Ursache sein?',
                'Die Ölkontrollleuchte leuchtet nach dem Starten weiter. Was könnte die mögliche Ursache sein?',
                'Der Motor ist gestartet worden. Was kann die Ursache sein, wenn nach dem Einkuppeln der Antriebswelle der Motor stehenbleibt?',
                'Ein Außenborder mit gefülltem Tank bleibt während der Fahrt stehen. Was könnten die Ursachen sein?',
            ],
            'Radeffekt' => [
                'Was ist unter einem rechtsdrehenden Propeller zu verstehen?',
                'Was ist unter einem linksdrehenden Propeller zu verstehen?',
                'Was ist unter der indirekten Ruderwirkung (Radeffekt) des Propellers zu verstehen?',
                'Weshalb ist die Kenntnis der Propellerdrehrichtung von Bedeutung?',
                'Welche Anlegeseite ist mit rechtsdrehendem Propeller empfehlenswert und warum?',
            ],
            'Lebensgefahr im Wasser' => [
                'In welcher Situation dürfen Notsignale gegeben werden?',
            ],
            'Tanken' => [
                'Was muss beim Tanken beachtet werden?',
            ],
            'Umweltschutz' => [
                'Welches Merkblatt enthält Hinweise für das Verhalten zum Schutz seltener Tiere und Pflanzen sowie zur Reinhaltung der Gewässer?',
                'Wie kann mitgeholfen werden, die Lebensmöglichkeiten der Pflanzen- und Tierwelt in Gewässern und Feuchtgebieten zu bewahren und zu fördern?',
                'Warum sollte man sich von Schilf- und Röhrichtzonen sowie von dicht bewachsenen Uferzonen möglichst weit fernhalten?',
                'Wo finden Sie Informationen über umweltfreundliche Farben, Lacke und Antifouling-Beschichtungen für Ihr Boot?',
                'Welche Veröffentlichungen enthalten wichtige Regeln und Tipps für Wassersportler, Empfehlungen zur Ausrüstung von Sportbooten sowie Hinweise zu umweltgerechtem Verhalten auf dem Wasser?',
            ],
            'Jetskis' => [
                'Was bewirkt der Quickstopp?',
            ],
        ],
        'Seemannschaft' => [
            'Ankern' => [
                'Woran kann man erkennen, ob der Anker hält?',
            ],
            'Feuer an Bord' => [
                'Welcher Feuerlöscher ist für Sportboote zweckmäßig und wie oft muss man einen Feuerlöscher überprüfen lassen?',
                'Welche Maßnahmen muss man ergreifen, um einen Brand mit dem Feuerlöscher wirksam zu bekämpfen?',
            ],
            'Nebel' => [
                'Welche besonderen Maßnahmen sind bei verminderter Sicht zu treffen?',
            ],
        ],
        'Wetterkunde' => [
            'Entstehung des Wetters' => [
                'Welche Faktoren sind hauptsächlich für das Wettergeschehen, also für Wind und Niederschläge, ausschlaggebend?',
            ],
            'Der Wind' => [
                'Für welche Windstärken wird eine Starkwindwarnung herausgegeben?',
                'Für welche Windstärken wird eine Sturmwarnung herausgegeben?',
            ],
            'Wetterkarten' => [
                'Wie werden Orte gleichen Luftdrucks in der Wetterkarte dargestellt und in welcher Maßeinheit wird der Luftdruck angegeben?',
                'Was bedeuten die in der Wetterkarte abgebildeten Isobaren?',
            ],
        ],
        'Ausweichregeln' => [
            'Ausweichregeln für alle Fahrzeuge' => [
                'Wie weichen zwei Motorboote aus, die sich auf entgegengesetzten Kursen nähern?',
                'Was bedeutet das "Manöver des letzten Augenblicks"?',
                'Wie müssen sich zwei in Sicht befindliche Maschinenfahrzeuge verhalten, die sich einander auf entgegengesetzten oder fast entgegengesetzten Kursen nähern, um die Möglichkeit der Gefahr eines Zusammenstoßes zu vermeiden?',
                'Wie verhält sich der Kurshaltepflichtige vor Einleitung des Manövers des letzten Augenblicks gegenüber einem ausweichpflichtigen Fahrzeug?',
                'Wie muss sich ein Ausweichpflichtiger gegenüber einem Kurshalter verhalten?',
                'Wie verhält man sich als Kurshalter, wenn man feststellt, dass ein anderes Fahrzeug seiner Ausweichpflicht nicht nachkommt und die Gefahr einer unmittelbaren Kollision bevorsteht?',
            ],
            'Ausweichregeln für Maschinenfahrzeuge' => [
                'Zwei Motorboote nähern sich auf kreuzenden Kursen. Es besteht die Gefahr eines Zusammenstoßes. Wer ist ausweichpflichtig?',
            ],
            'Ausweichregeln für Segelfahrzeuge' => [
                'Welche Seite wird als Luvseite bezeichnet?',
                'Welche Seite wird als Leeseite bezeichnet?',
            ],
        ],
        'Berufsschifffahrt' => [
            'Seeverkehrsrecht' => [
                'Wann ist ein Fahrzeug in Fahrt?',
                'Wo gelten die Kollisionsverhütungsregeln (KVR)?',
                'Was gilt, wenn eine Bestimmung der Seeschifffahrtsstraßen-Ordnung (SeeSchStrO) mit den Kollisionsverhütungsregeln (KVR) im Widerspruch steht?',
            ],
            'Seefunk' => [
                'Wozu dient primär das Automatische Identifikationssystem (AIS)?',
                'Welche Informationen können über das Automatische Identifikationssystem (AIS) ausgetauscht werden?',
            ],
            'Verkehrstrennungsgebiete' => [
                'Was sind "Verkehrstrennungsgebiete"?',
                'Wie sind Verkehrstrennungsgebiete zu befahren?',
            ],
            'Schallsignale' => [
                'Wie lang ist die Dauer eines kurzen Tons ( )?',
                'Wie lang ist die Dauer eines langen Tons ( )?',
            ],
        ],
        'Lichter und Signale' => [
            'Positionslaternen' => [
                'Was für eine Laterne kann ein Segelfahrzeug von weniger als 20 m Länge anstelle der Seitenlichter und des Hecklichtes führen?',
            ],
            'Spezialfälle' => [
                ['media' => 'Lichter-Frage-93-gif.png'],
                ['media' => 'Lichter-Frage-107-gif.png'],
                ['media' => 'Lichter-Frage-105-gif.png'],
                ['media' => 'Lichter-Frage-108-gif.png'],
                ['media' => 'Lichter-Frage-110-gif.png'],
                ['media' => 'Lichter-Frage-111-gif.png'],
            ],
            'Signalkörper und Flaggen' => [
                'Was bedeutet es, wenn jedes Fahrzeug eines Schleppverbandes einen schwarzen Rhombus führt?',
                ['media' => 'Signalkoerper-Frage-99-gif.png'],
                ['media' => 'Signalkoerper-Frage-104-gif.png'],
                ['media' => 'Signalkoerper-Frage-106-gif.png'],
                ['media' => 'Signalkoerper-Frage-109-gif.png'],
                ['media' => 'Signalkoerper-Frage-112-gif.png'],
            ],
        ],
        'Leuchtfeuer und Tonnen' => [
            'Feuerarten und Kennungen' => [
                'Was versteht man unter einem unterbrochenen Feuer?',
                'Was versteht man unter einem Blinkfeuer?',
                'Was versteht man unter einem Blitzfeuer?',
                'Was versteht man unter einem Funkelfeuer?',
            ],
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
            $chapter = $sbfSee->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            foreach ($lessons as $lessonTitle => $questionTexts) {
                $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();
                $this->attachQuestions($lesson, $questionTexts);
            }
        }
    }

    /**
     * @param array<int, string|array{media: string}> $questions Fragetext, oder
     *        ['media' => '...'] um bei mehrdeutigem (mehrfach verwendetem)
     *        Fragetext über den Dateinamen des zugehörigen Bildes zu identifizieren.
     */
    private function attachQuestions(?VideoLesson $lesson, array $questions): void
    {
        if (! $lesson || $lesson->questions()->exists()) {
            return;
        }

        foreach ($questions as $sortOrder => $question) {
            $query = ContentQuestionRevision::where('editorial_status', 'published');

            $revision = is_array($question)
                ? $query->whereHas('media', fn ($q) => $q->where('storage_path', 'ilike', '%' . $question['media']))->first()
                : $query->where('question_text', $question)->first();

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
