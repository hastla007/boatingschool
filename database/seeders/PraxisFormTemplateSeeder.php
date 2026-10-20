<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisFormTemplate;
use Illuminate\Database\Seeder;

/**
 * Demo-/Startbestückung der Praxisformulare (siehe PraxisFormTemplate):
 * jeder Kurs bekommt genau ein zugewiesenes Formular (Exklusivität),
 * mehrere verwandte Kurse teilen sich dabei dasselbe Formular. Felder und
 * Schwellen sind redaktionelle Startwerte, im Superadmin jederzeit
 * änderbar.
 */
class PraxisFormTemplateSeeder extends Seeder
{
    private const PRAXIS_FIELDS = [
        [
            'key' => 'ort',
            'label' => 'Ort',
            'type' => 'select',
            'required' => true,
            'options' => ['Hauptstandort', 'Nebenstandort'],
            'help_text' => null,
        ],
        [
            'key' => 'wunschdatum',
            'label' => 'Wunschdatum',
            'type' => 'date',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geburtsort',
            'label' => 'Geburtsort',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'nationalitaet',
            'label' => 'Nationalität',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => 'z. B. Deutsch, Österreichisch',
        ],
        [
            'key' => 'telefonnummer',
            'label' => 'Telefonnummer',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geschlecht',
            'label' => 'Geschlecht',
            'type' => 'radio',
            'required' => true,
            'options' => ['Männlich', 'Weiblich', 'Divers'],
            'help_text' => null,
        ],
        [
            'key' => 'tauglichkeit_vorhanden',
            'label' => 'Ich habe bereits die Tauglichkeit',
            'type' => 'checkbox',
            'required' => false,
            'options' => [],
            'help_text' => 'Du brauchst für die Prüfung eine ärztliche Tauglichkeitsbescheinigung. Plane dafür ein paar Tage Vorlauf ein.',
        ],
        [
            'key' => 'vorlaeufiger_fuehrerschein',
            'label' => 'Ich benötige zusätzlich einen vorläufigen Führerschein für 24,90 €',
            'type' => 'checkbox',
            'required' => false,
            'options' => [],
            'help_text' => null,
        ],
    ];

    private const THEORIE_FIELDS = [
        [
            'key' => 'wunschtermin',
            'label' => 'Wunschtermin',
            'type' => 'date',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'geburtsort',
            'label' => 'Geburtsort',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
        [
            'key' => 'nationalitaet',
            'label' => 'Nationalität',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => 'z. B. Deutsch, Österreichisch',
        ],
        [
            'key' => 'telefonnummer',
            'label' => 'Telefonnummer',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'help_text' => null,
        ],
    ];

    public function run(): void
    {
        $this->template('Praxis & Prüfung Motorboot', 50, self::PRAXIS_FIELDS, ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN']);
        $this->template('Praxis & Prüfung Segeln', 50, self::PRAXIS_FIELDS, ['SBF-BIN-SEGEL', 'ADD-BIN-SEGEL']);
        $this->template('Praxis & Prüfung Ergänzungskurs', 50, self::PRAXIS_FIELDS, ['ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE']);
        $this->template('Anmeldung Funkzeugnis-Prüfung', 80, self::THEORIE_FIELDS, ['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC']);
        $this->template('Anmeldung Pyroschein-Prüfung', 70, self::THEORIE_FIELDS, ['FKN', 'SKN', 'ADD-FKN-TO-SKN']);
    }

    /** @param string[] $courseCodes */
    private function template(string $title, int $minProgressPercent, array $fields, array $courseCodes): void
    {
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->whereIn('code', $courseCodes)->pluck('id')->values()->all();

        if (empty($courseIds)) {
            return;
        }

        PraxisFormTemplate::updateOrCreate(
            ['title' => $title],
            [
                'min_progress_percent' => $minProgressPercent,
                'fields' => $fields,
                'course_ids' => $courseIds,
                'active' => true,
            ]
        );
    }
}
