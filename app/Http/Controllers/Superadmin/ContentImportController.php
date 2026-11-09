<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Fragen-Import per Upload: dieselben Befehle wie auf der Kommandozeile
 * (content:import für die Excel-Fragenbasis, content:import-feedback für
 * die Erklärungen als CSV), mit dem Ergebnis als Bericht. Geänderte
 * Fragen landen wie beim CLI-Import als Revision „review“ und werden
 * erst nach Prüfung im Fragen-Editor veröffentlicht.
 */
class ContentImportController extends Controller
{
    /** Zeilen der Befehlsausgabe, die in den Bericht gehören (ohne Fortschrittsbalken). */
    private const REPORT_LINE = '/^(Importiert|Unverändert|Neue Revision|Übersprungen|Feedback aktualisiert|Datei nicht gefunden|Blatt |Keine Module|\s+Zeile \d+)/u';

    public function index(): View
    {
        return view('superadmin.content-import.index', ['report' => session('import_report')]);
    }

    public function questions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'sheet' => ['nullable', 'string', 'max:100'],
        ], ['file.mimes' => 'Bitte eine Excel-Datei (.xlsx) hochladen.']);

        return $this->run($request, $request->file('file'), 'content:import', ['--sheet' => ($validated['sheet'] ?? null) ?: 'Central Question Pool'], 'Fragenbasis');
    }

    public function feedback(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], ['file.mimes' => 'Bitte eine CSV-Datei hochladen.']);

        return $this->run($request, $request->file('file'), 'content:import-feedback', [], 'Erklärungen');
    }

    /** @param  array<string, string>  $options */
    private function run(Request $request, UploadedFile $file, string $command, array $options, string $label): RedirectResponse
    {
        @set_time_limit(600);
        $stored = $file->storeAs('imports', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        $absolute = Storage::disk('local')->path($stored);
        $relative = ltrim(Str::after($absolute, base_path()), '/');

        $output = new BufferedOutput;
        $failure = null;
        try {
            $exitCode = Artisan::call($command, ['path' => $relative] + $options, $output);
        } catch (\Throwable $e) {
            // Abbruch mitten im Import: Bericht und Protokoll trotzdem
            // schreiben, damit sichtbar ist, dass nur ein Teil übernommen wurde.
            report($e);
            $exitCode = 1;
            $failure = 'Import abgebrochen ('.class_basename($e).'): '.Str::limit($e->getMessage(), 300).' – Zeilen davor wurden bereits übernommen.';
        } finally {
            Storage::disk('local')->delete($stored);
        }

        $lines = collect(preg_split('/\R/u', $output->fetch()))
            ->map(fn ($line) => rtrim($line))
            ->filter(fn ($line) => preg_match(self::REPORT_LINE, $line))
            ->map(fn ($line) => trim(str_replace(Storage::disk('local')->path(''), '', $line)))
            ->when($failure, fn ($c) => $c->push($failure))
            ->values()->all();

        AuditLog::record(null, $request->user()->id, 'superadmin.content_import', 'content_question', null, null, [
            'command' => $command, 'file' => Str::limit($file->getClientOriginalName(), 120), 'ok' => $exitCode === 0, 'summary' => array_slice($lines, 0, 5),
        ]);

        return redirect()->route('superadmin.content-import.index')->with('import_report', [
            'label' => $label,
            'file' => $file->getClientOriginalName(),
            'ok' => $exitCode === 0,
            'lines' => $lines,
        ]);
    }
}
