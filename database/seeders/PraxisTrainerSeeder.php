<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Praxistrainer für die praktische SBF-See-Prüfung, importiert aus
 * database/data/sbf_see_praxistrainer.csv (Bilder in
 * database/data/praxistrainer_bilder/, vom Betreiber bereitgestellt).
 * Bilder werden wie bei den Prüfungsbögen (ExamPaperSeeder) lokal unter
 * storage/app/public/question-media abgelegt statt extern verlinkt.
 */
class PraxisTrainerSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->praxisTasks()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_see_praxistrainer.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $sortOrder = 0;
        foreach ($this->readCsv($csvPath) as $row) {
            $sortOrder++;

            $asset = $this->attachImage($row['bilddatei'] ?? '');

            PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => $row['id'],
                'kategorie' => $row['kategorie'],
                'unterkategorie' => $row['unterkategorie'] ?: null,
                'pruefungsbezug' => $row['pruefungsbezug'] ?: null,
                'aufgabentyp' => $row['aufgabentyp'] ?: null,
                'frage' => $row['frage'],
                'antwort' => $row['antwort'],
                'erklaerung' => $row['erklaerung'] ?: null,
                'media_asset_id' => $asset?->id,
                'quelle' => $row['quelle'] ?: null,
                'quellen_url' => $row['quellen_url'] ?: null,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    private function attachImage(string $imagePath): ?MediaAsset
    {
        $filename = basename(trim($imagePath));
        if ($filename === '') {
            return null;
        }

        $sourcePath = database_path('data/praxistrainer_bilder/'.$filename);
        if (! file_exists($sourcePath)) {
            return null;
        }

        $storagePath = 'question-media/praxistrainer/'.$filename;
        if (! Storage::disk('public')->exists($storagePath)) {
            Storage::disk('public')->put($storagePath, file_get_contents($sourcePath));
        }

        return MediaAsset::firstOrCreate(
            ['asset_key' => 'praxistrainer_'.$filename],
            [
                'media_type' => 'image',
                'storage_path' => Storage::disk('public')->url($storagePath),
                'mime_type' => 'image/png',
                'source' => 'SBF See Praxistrainer',
            ]
        );
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
