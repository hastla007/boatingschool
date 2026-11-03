<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\RadioTrainingProgress;
use App\Services\EntitlementService;
use App\Support\Geraetesimulation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gerätesimulation Funk (IC-M505, Seefunk): Seite mit Gerät, Lektionen,
 * Prüfungsmodus und Freiem Üben, Bewertung gesprochener Funksprüche,
 * Lernstand je Lektion und bestes Ergebnis je Prüfungsreihe.
 * Sichtbar nach config('funk.geraetesimulation'), in der Beta nur für
 * Superadmins; dazu wie im übrigen Funk-Training der Kurszugang.
 */
class GeraetesimulationController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $this->authorizeCourse($request, $tenantContext, $entitlements, $course);
        $data = Geraetesimulation::data();

        // Lernstand je Gerät: "ic-m505:<lektion>", "ic-m323:pruefung-a" …
        // Nur Geräte, die einen Funkschein des Kurses abdecken.
        $catalogs = Geraetesimulation::catalogsFor($course);
        $deviceList = Geraetesimulation::devicesFor($catalogs);
        $devices = array_column($deviceList, 'key');
        $rows = RadioTrainingProgress::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('item_type', RadioTrainingProgress::TYPE_DEVICE)
            ->where(fn ($q) => collect($devices)->each(fn ($device) => $q->orWhere('item_key', 'like', $device.':%')))
            ->get()
            ->keyBy('item_key');
        $progress = collect($devices)->mapWithKeys(fn ($device) => [$device => $rows
            ->filter(fn ($p, $key) => str_starts_with($key, $device.':') && ! str_contains($key, ':pruefung-'))
            ->mapWithKeys(fn ($p, $key) => [substr($key, strlen($device) + 1) => (int) ($p->answers['level'] ?? 0)])]);
        $exams = collect(Geraetesimulation::exams($catalogs))->map(fn ($exam, $series) => $exam + [
            'best' => collect($devices)->mapWithKeys(fn ($device) => [$device => $rows->get(Geraetesimulation::examKey($series, $device))?->answers['best'] ?? null]),
        ]);

        return view('funk.geraetesimulation', [
            'course' => $course,
            'config' => [
                'devices' => $deviceList,
                'ship' => $data['ship'],
                'addressBook' => $data['address_book'],
                'directory' => $data['directory'],
                'catalogs' => $catalogs,
                'lessons' => Geraetesimulation::lessonsForBrowser($catalogs),
                'progress' => $progress,
                'exams' => $exams,
                'speechLang' => 'en-GB',
                'urls' => [
                    'evaluate' => route('funk.device.evaluate', $course),
                    'progress' => route('funk.device.progress', $course),
                    'exam' => route('funk.device.exam', $course),
                ],
            ],
        ]);
    }

    public function evaluate(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): JsonResponse
    {
        $this->authorizeCourse($request, $tenantContext, $entitlements, $course);
        $validated = $request->validate([
            'lesson' => ['required', 'string', Rule::in(Geraetesimulation::lessonKeys(Geraetesimulation::catalogsFor($course)))],
            'step' => ['required', 'integer', 'min:0', 'max:50'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $result = Geraetesimulation::evaluate($validated['lesson'], (int) $validated['step'], $validated['text']);
        abort_if($result === null, 422, 'Dieser Schritt erwartet keinen Funkspruch.');

        return response()->json($result);
    }

    public function progress(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): JsonResponse
    {
        $this->authorizeCourse($request, $tenantContext, $entitlements, $course);
        $validated = $request->validate([
            'lesson' => ['required', 'string', Rule::in(Geraetesimulation::lessonKeys(Geraetesimulation::catalogsFor($course)))],
            'level' => ['required', 'integer', 'min:1', 'max:3'],
            'device' => ['sometimes', 'string', Rule::in(Geraetesimulation::deviceKeys())],
        ]);
        $device = $validated['device'] ?? Geraetesimulation::DEVICE;
        // Auf diesem Gerät ausgeblendete Lektionen (z. B. Positionsabfrage am IC-M323).
        abort_unless(Geraetesimulation::lessonAvailable(Geraetesimulation::lesson($validated['lesson']), $device), 422, 'Diese Lektion gibt es auf diesem Gerät nicht.');

        $key = Geraetesimulation::progressKey($validated['lesson'], $device);
        $attributes = ['tenant_id' => $tenantContext->id(), 'user_id' => $request->user()->id, 'item_type' => RadioTrainingProgress::TYPE_DEVICE, 'item_key' => $key];
        $current = RadioTrainingProgress::where($attributes)->first();
        // Die beste Stufe bleibt: ein späterer Durchgang mit Hilfe senkt sie nicht.
        $level = max((int) ($current?->answers['level'] ?? 0), (int) $validated['level']);

        RadioTrainingProgress::updateOrCreate($attributes, ['answers' => ['level' => $level], 'completed' => $level >= 3, 'updated_at' => now()]);

        return response()->json(['level' => $level]);
    }

    /**
     * Ende einer Prüfungsreihe: speichert das beste Ergebnis (bestandene
     * Aufgaben) und gibt die Musterlösungen aller Aufgaben zurück. Die
     * Lösungen stehen nicht in den Lektionsdaten der Seite, sondern kommen
     * erst nach der Prüfung von hier. Das Ergebnis meldet der Browser; es
     * ist ein Übungsstand für die Lernenden selbst, kein amtlicher Nachweis.
     */
    public function exam(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): JsonResponse
    {
        $this->authorizeCourse($request, $tenantContext, $entitlements, $course);
        $exams = Geraetesimulation::exams(Geraetesimulation::catalogsFor($course));
        $validated = $request->validate([
            'series' => ['required', 'string', Rule::in(array_keys($exams))],
            'device' => ['sometimes', 'string', Rule::in(Geraetesimulation::deviceKeys())],
            'passed' => ['present', 'array'],
            'passed.*' => ['string', 'distinct'],
        ]);
        $device = $validated['device'] ?? Geraetesimulation::DEVICE;
        // Prüfungsreihe eines Funkscheins, den das Gerät abdeckt (z. B. kein UBI am IC-M94DE).
        abort_unless(in_array($exams[$validated['series']]['catalog'], Geraetesimulation::deviceCatalogs($device), true), 422, 'Diese Prüfung gibt es auf diesem Gerät nicht.');
        $lessons = Geraetesimulation::examLessons($validated['series'], $device);
        $passed = array_values(array_intersect($lessons, $validated['passed']));

        $key = Geraetesimulation::examKey($validated['series'], $device);
        $attributes = ['tenant_id' => $tenantContext->id(), 'user_id' => $request->user()->id, 'item_type' => RadioTrainingProgress::TYPE_DEVICE, 'item_key' => $key];
        $current = RadioTrainingProgress::where($attributes)->first();
        $best = max((int) ($current?->answers['best'] ?? 0), count($passed));
        RadioTrainingProgress::updateOrCreate($attributes, ['answers' => ['best' => $best, 'total' => count($lessons), 'last' => $passed], 'completed' => $best === count($lessons), 'updated_at' => now()]);

        return response()->json([
            'passed' => count($passed),
            'total' => count($lessons),
            'best' => $best,
            'solutions' => collect($lessons)->mapWithKeys(fn ($lesson) => [$lesson => Geraetesimulation::solutions($lesson)]),
        ]);
    }

    private function authorizeCourse(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): void
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);
        abort_unless(Geraetesimulation::availableFor($request->user(), $course), 404);
    }
}
