<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Praxistrainer-Karten eines Kurses pflegen: bearbeiten, neu anlegen,
 * Bild tauschen, deaktivieren. Deaktivieren statt löschen: der Lernstand
 * bleibt erhalten und die Seeder legen die Karte nicht erneut an.
 */
class PraxisTaskController extends Controller
{
    private const CUSTOM_ASSET_PREFIX = 'praxis-custom-';

    public function index(CourseDefinition $course): View
    {
        $tasks = $course->allPraxisTasks()->with('media')->get();

        return view('superadmin.praxis-tasks.index', [
            'course' => $course,
            'groups' => $tasks->groupBy('kategorie'),
            'activeCount' => $tasks->where('active', true)->count(),
        ]);
    }

    public function create(CourseDefinition $course): View
    {
        return view('superadmin.praxis-tasks.form', ['course' => $course, 'task' => null, 'categories' => $this->categories($course)]);
    }

    public function store(Request $request, CourseDefinition $course): RedirectResponse
    {
        $validated = $this->validated($request);
        $task = new PraxisTask($this->fields($validated) + [
            'course_id' => $course->id,
            'content_id' => 'SA-'.Str::upper(Str::random(10)),
            'sort_order' => $validated['sort_order'] ?? ((int) $course->allPraxisTasks()->max('sort_order') + 1),
        ]);
        $task->media_asset_id = $this->storeImage($request, $validated);
        $task->save();
        AuditLog::record(null, $request->user()->id, 'superadmin.praxis_task.create', 'praxis_task', $task->id, null, ['content_id' => $task->content_id]);

        return redirect()->route('superadmin.praxis-tasks.index', $course)->with('status', 'Karte angelegt.');
    }

    public function edit(PraxisTask $task): View
    {
        $task->load('media', 'course');

        return view('superadmin.praxis-tasks.form', ['course' => $task->course, 'task' => $task, 'categories' => $this->categories($task->course)]);
    }

    public function update(Request $request, PraxisTask $task): RedirectResponse
    {
        $validated = $this->validated($request);
        $before = $task->only(['frage', 'antwort', 'kategorie', 'unterkategorie']);
        $textBefore = $task->only(['frage', 'antwort', 'erklaerung']);
        $task->fill($this->fields($validated));
        if (isset($validated['sort_order'])) {
            $task->sort_order = $validated['sort_order'];
        }

        $oldAsset = $task->media_asset_id;
        if ($request->hasFile('image')) {
            $task->media_asset_id = $this->storeImage($request, $validated);
        } elseif ($request->boolean('remove_image')) {
            $task->media_asset_id = null;
        } elseif ($task->media && array_key_exists('alt_text', $validated)) {
            $task->media->update(['alt_text' => $validated['alt_text']]);
        }
        $task->save();
        if ($oldAsset && $oldAsset !== $task->media_asset_id) {
            $this->deleteCustomAssetIfUnused($oldAsset);
        }
        // Kopien in Kombikursen (content_id mit Zusatz, siehe
        // ComboPraxisTrainerSeeder) mit unverändertem Text gleich mitkorrigieren;
        // eigenständig geänderte Kopien bleiben unangetastet.
        $copies = PraxisTask::where('id', '!=', $task->id)->where('content_id', 'like', addcslashes($task->content_id, '%_\\').'%')
            ->where(fn ($q) => collect($textBefore)->each(fn ($value, $field) => $value === null ? $q->whereNull($field) : $q->where($field, $value)))
            ->get();
        foreach ($copies as $copy) {
            $copy->update($task->only(['frage', 'antwort', 'erklaerung']));
        }
        AuditLog::record(null, $request->user()->id, 'superadmin.praxis_task.update', 'praxis_task', $task->id, $before, $task->only(['frage', 'antwort', 'kategorie', 'unterkategorie']) + ['copies' => $copies->count()]);

        return redirect()->route('superadmin.praxis-tasks.index', $task->course_id)
            ->with('status', 'Karte gespeichert'.($copies->isNotEmpty() ? ', auch in '.$copies->count().' Kopie(n) in Kombikursen.' : '.'));
    }

    public function toggleActive(Request $request, PraxisTask $task): RedirectResponse
    {
        $task->update(['active' => ! $task->active]);
        AuditLog::record(null, $request->user()->id, 'superadmin.praxis_task.'.($task->active ? 'activate' : 'deactivate'), 'praxis_task', $task->id, null, ['content_id' => $task->content_id]);

        return redirect()->to(route('superadmin.praxis-tasks.index', $task->course_id).'#karte-'.$task->id)
            ->with('status', $task->active ? 'Karte aktiviert.' : 'Karte deaktiviert. Lernende sehen sie nicht mehr.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kategorie' => ['required', 'string', 'max:255'],
            'unterkategorie' => ['nullable', 'string', 'max:255'],
            'pruefungsbezug' => ['nullable', 'string', 'max:255'],
            'aufgabentyp' => ['nullable', 'string', 'max:255'],
            'frage' => ['required', 'string', 'max:5000'],
            'antwort' => ['required', 'string', 'max:10000'],
            'erklaerung' => ['nullable', 'string', 'max:10000'],
            'quelle' => ['nullable', 'string', 'max:255'],
            'quellen_url' => ['nullable', 'string', 'max:2048', 'url:https,http'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function fields(array $validated): array
    {
        return collect($validated)->only(['kategorie', 'unterkategorie', 'pruefungsbezug', 'aufgabentyp', 'frage', 'antwort', 'erklaerung', 'quelle', 'quellen_url'])
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();
    }

    private function storeImage(Request $request, array $validated): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }
        $file = $request->file('image');
        $path = $file->storeAs('question-media/praxistrainer/eigene', Str::uuid().'.'.$file->extension(), 'public');

        return MediaAsset::create([
            'asset_key' => self::CUSTOM_ASSET_PREFIX.Str::random(16),
            'media_type' => 'image',
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'alt_text' => $validated['alt_text'] ?? null,
            'source' => 'Superadmin-Upload',
            'rights_status' => 'licensed',
            'status' => 'published',
        ])->id;
    }

    /** Selbst hochgeladene Bilder löschen, sobald keine Karte sie mehr nutzt (mitgelieferte bleiben). */
    private function deleteCustomAssetIfUnused(string $assetId): void
    {
        $asset = MediaAsset::find($assetId);
        if (! $asset || ! str_starts_with($asset->asset_key, self::CUSTOM_ASSET_PREFIX) || PraxisTask::where('media_asset_id', $assetId)->exists()) {
            return;
        }
        Storage::disk('public')->delete($asset->getRawOriginal('storage_path'));
        $asset->delete();
    }

    /** @return list<string> */
    private function categories(CourseDefinition $course): array
    {
        return $course->allPraxisTasks()->reorder()->distinct()->orderBy('kategorie')->pluck('kategorie')->all();
    }
}
