<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\MediaAsset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Die 15 amtlichen Prüfungsbögen für SBF See, importiert aus
 * database/data/sbf_see_pruefungsboegen.csv (Quelle: die einzelnen Bögen
 * unter bootsfuehrerscheinpruefung.de/sbfsee/pruefungsboegen/, vom Betreiber
 * bereitgestellt). Jede Zeile referenziert eine Frage über ihre
 * Fragenkatalog-Nr. (= content_question.official_number), eindeutig erst in
 * Kombination mit der Fragenfamilie ("Basisfragen" vs. "Spezifische Fragen
 * See" aus der Tags-Spalte), da dieselbe Katalognummer je nach Familie
 * mehrfach vorkommt (z. B. Basis-Frage 8 und SRC-Frage 8 sind verschiedene
 * Fragen). Bilder, die in den Prüfungsbögen verwendet werden, werden dabei
 * gleich an die jeweilige Frage angehängt (question_media), sodass sie
 * überall erscheinen, wo diese Frage angezeigt wird (Smarttrainer,
 * Prüfungssimulation, Navigationsaufgaben-ähnliche Ansichten).
 */
class ExamPaperSeeder extends Seeder
{
    private const CONTENT_ROLE_BY_TAG = [
        'Basisfragen' => 'shared_basis',
        'Spezifische Fragen See' => 'see_specific',
    ];

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_see_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Prüfungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);
            $contentRole = $this->resolveContentRole($row['Tags'] ?? '');

            $question = $contentRole
                ? ContentQuestion::where('official_number', $officialNumber)->where('content_role', $contentRole)->first()
                : null;

            if (! $question) {
                continue;
            }

            $paper = $papers[$paperNumber] ??= ExamPaper::create([
                'course_id' => $course->id,
                'paper_number' => $paperNumber,
                'sort_order' => $paperNumber,
            ]);

            ExamPaperQuestion::create([
                'exam_paper_id' => $paper->id,
                'question_id' => $question->id,
                'position' => $position,
            ]);

            $this->attachImages($question, $row['Bilddateien im ZIP'] ?? '');
        }
    }

    private function resolveContentRole(string $tagsCell): ?string
    {
        foreach (self::CONTENT_ROLE_BY_TAG as $tag => $role) {
            if (str_contains($tagsCell, $tag)) {
                return $role;
            }
        }

        return null;
    }

    private function attachImages(ContentQuestion $question, string $imagesCell): void
    {
        $filenames = array_filter(array_map('trim', explode(';', $imagesCell)));

        if (empty($filenames)) {
            return;
        }

        $revision = $question->publishedRevision();
        if (! $revision) {
            return;
        }

        foreach ($filenames as $index => $imagePath) {
            $filename = basename($imagePath);
            $sourcePath = database_path('data/pruefungsboegen_bilder/'.$filename);

            if (! file_exists($sourcePath)) {
                continue;
            }

            $storagePath = 'question-media/'.$filename;
            if (! Storage::disk('public')->exists($storagePath)) {
                Storage::disk('public')->put($storagePath, file_get_contents($sourcePath));
            }

            $asset = MediaAsset::firstOrCreate(
                ['asset_key' => $filename],
                [
                    'media_type' => 'image',
                    'storage_path' => Storage::disk('public')->url($storagePath),
                    'mime_type' => 'image/png',
                    'source' => 'bootsfuehrerscheinpruefung.de',
                ]
            );

            if (! $revision->media()->where('media_asset_id', $asset->id)->exists()) {
                $revision->media()->attach($asset->id, ['role' => 'question', 'sort_order' => $index + 1]);
            }
        }
    }

    /** @return iterable<array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }
}
