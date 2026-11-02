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

        $rows = RadioTrainingProgress::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('item_type', RadioTrainingProgress::TYPE_DEVICE)
            ->where('item_key', 'like', Geraetesimulation::DEVICE.':%')
            ->get()
            ->keyBy(fn ($p) => substr($p->item_key, strlen(Geraetesimulation::DEVICE) + 1));
        $progress = $rows->reject(fn ($p, $key) => str_starts_with($key, 'pruefung-'))->map(fn ($p) => (int) ($p->answers['level'] ?? 0));
        $exams = collect($data['exams'])->map(fn ($lessons, $series) => [
            'lessons' => $lessons,
            'best' => $rows->get('pruefung-'.$series)?->answers['best'] ?? null,
        ]);

        return view('funk.geraetesimulation', [
            'course' => $course,
            'config' => [
                'device' => Geraetesimulation::DEVICE_NAME,
                'ship' => $data['ship'],
                'addressBook' => $data['address_book'],
                'directory' => $data['directory'],
                'lessons' => Geraetesimulation::lessonsForBrowser(),
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
            'lesson' => ['required', 'string', Rule::in(array_column(Geraetesimulation::data()['lessons'], 'key'))],
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
            'lesson' => ['required', 'string', Rule::in(array_column(Geraetesimulation::data()['lessons'], 'key'))],
            'level' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        $key = Geraetesimulation::progressKey($validated['lesson']);
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
     * Lösungen gibt es erst hier, damit sie während der Prüfung nicht im
     * Browser stehen.
     */
    public function exam(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): JsonResponse
    {
        $this->authorizeCourse($request, $tenantContext, $entitlements, $course);
        $exams = Geraetesimulation::data()['exams'];
        $validated = $request->validate([
            'series' => ['required', 'string', Rule::in(array_keys($exams))],
            'passed' => ['present', 'array'],
            'passed.*' => ['string', 'distinct'],
        ]);
        $lessons = $exams[$validated['series']];
        $passed = array_values(array_intersect($lessons, $validated['passed']));

        $key = Geraetesimulation::examKey($validated['series']);
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
