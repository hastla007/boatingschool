<?php

namespace App\Console\Commands;

use App\Models\ContentQuestion;
use Illuminate\Console\Command;

/**
 * Backfill von Richtig-/Falsch-Feedback für bereits importierte Fragen
 * (siehe database/data/sbf_see_binnen_fragen_feedback.csv). Anders als
 * ImportContentQuestions erzeugt dieser Import keine neue Revision: Frage
 * und Antworten ändern sich nicht, nur die erklärenden Feedback-Texte
 * werden auf der aktuell veröffentlichten Revision ergänzt. Die Feedback-
 * Spalten der Quelldatei beginnen redundant mit "Richtig."/"Falsch." --
 * das steht in der Oberfläche bereits als eigene Überschrift, deshalb wird
 * diese erste Zeile beim Import abgeschnitten. "Falsch."-Texte wiederholen
 * zusätzlich noch die richtige Antwort als eigene Zeile ("Richtig ist:
 * „...“") -- das steht in der Oberfläche bereits als "Richtige Antwort:
 * ..." darüber, deshalb wird auch diese Zeile beim Import entfernt.
 */
class ImportContentFeedback extends Command
{
    protected $signature = 'content:import-feedback {path=database/data/sbf_see_binnen_fragen_feedback.csv}';

    protected $description = 'Ergänzt Richtig-/Falsch-Feedback auf bereits importierten Fragen aus einer CSV-Datei.';

    private int $updated = 0;

    private int $unchanged = 0;

    private int $skipped = 0;

    /** @var array<int,string> */
    private array $errors = [];

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (! is_file($path)) {
            $this->error("Datei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $rows = iterator_to_array($this->readCsv($path));

        $this->withProgressBar($rows, function (array $row, $bar, int $rowIndex) {
            $this->importRow($row, $rowIndex + 2);
        });
        $this->newLine(2);

        $this->info("Feedback aktualisiert: {$this->updated}");
        $this->info("Unverändert (bereits identisch): {$this->unchanged}");
        $this->warn("Übersprungen/Fehler: {$this->skipped}");

        foreach ($this->errors as $line => $message) {
            $this->line("  Zeile {$line}: {$message}");
        }

        return self::SUCCESS;
    }

    private function importRow(array $row, int $lineNumber): void
    {
        $contentId = trim((string) ($row['content_id'] ?? ''));
        if ($contentId === '') {
            $this->recordSkip($lineNumber, 'content_id fehlt.');

            return;
        }

        $feedbackCorrect = $this->stripLeadingVerdict(trim((string) ($row['feedback_correct'] ?? '')), 'Richtig.');
        $feedbackIncorrect = $this->stripLeadingVerdict(trim((string) ($row['feedback_incorrect'] ?? '')), 'Falsch.');
        $feedbackIncorrect = $this->stripLeadingCorrectAnswerLine($feedbackIncorrect);

        if ($feedbackCorrect === '' && $feedbackIncorrect === '') {
            $this->recordSkip($lineNumber, "Kein Feedback für '{$contentId}' in der Quelldatei.");

            return;
        }

        $question = ContentQuestion::where('content_id', $contentId)->first();
        if (! $question) {
            $this->recordSkip($lineNumber, "Keine Frage mit content_id '{$contentId}' gefunden.");

            return;
        }

        $revision = $question->publishedRevision() ?? $question->revisions()->orderByDesc('revision_no')->first();
        if (! $revision) {
            $this->recordSkip($lineNumber, "Keine Revision für '{$contentId}' gefunden.");

            return;
        }

        if ($revision->feedback_correct === $feedbackCorrect && $revision->feedback_incorrect === $feedbackIncorrect) {
            $this->unchanged++;

            return;
        }

        $revision->update([
            'feedback_correct' => $feedbackCorrect ?: null,
            'feedback_incorrect' => $feedbackIncorrect ?: null,
        ]);
        $this->updated++;
    }

    private function stripLeadingVerdict(string $text, string $verdict): string
    {
        if (str_starts_with($text, $verdict)) {
            $text = substr($text, strlen($verdict));
        }

        return trim($text);
    }

    /** Entfernt die führende "Richtig ist: „...“"-Zeile, die die App bereits als "Richtige Antwort: ..." anzeigt. */
    private function stripLeadingCorrectAnswerLine(string $text): string
    {
        return trim(preg_replace('/^Richtig ist: „[^“]*“\s*/u', '', $text, 1));
    }

    /** @return iterable<int, array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }

    private function recordSkip(int $lineNumber, string $message): void
    {
        $this->skipped++;
        $this->errors[$lineNumber] = $message;
    }
}
