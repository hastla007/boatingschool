<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Prüfungsregeln eines Kurses: Zeitlimit, Fragenmix (Blueprint je Modul),
 * Bestehensgrenze (gesamt und je Prüfungsteil), Hinweis, Status und
 * fachliche Freigabe. Gestartet werden kann eine Prüfung nur mit einem
 * veröffentlichten und als geprüft markierten Regelwerk.
 */
class ExamRuleSetController extends Controller
{
    public function index(CourseDefinition $course): View
    {
        $ruleSets = $course->examRuleSets()->with(['blueprints.module'])->orderByDesc('valid_from')->get();

        return view('superadmin.exam-rules.index', [
            'course' => $course,
            'ruleSets' => $ruleSets,
            'modules' => Module::orderBy('name')->get(['id', 'code', 'name']),
            'activeId' => $course->activeExamRuleSet()?->id,
        ]);
    }

    public function update(Request $request, ExamRuleSet $ruleSet): RedirectResponse
    {
        $validated = $request->validate([
            'time_limit_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'min_correct' => ['required', 'integer', 'min:1', 'lte:of'],
            'of' => ['required', 'integer', 'min:1', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'verified' => ['nullable', 'boolean'],
            'blueprints' => ['array'],
            'blueprints.*.module_id' => ['nullable', 'uuid', 'exists:module,id'],
            'blueprints.*.question_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parts' => ['array'],
            'parts.*.label' => ['nullable', 'string', 'max:60'],
            'parts.*.module' => ['nullable', 'string', 'exists:module,code'],
            'parts.*.min_correct' => ['nullable', 'integer', 'min:0'],
            'parts.*.of' => ['nullable', 'integer', 'min:1'],
        ], [
            'min_correct.lte' => 'Die Bestehensgrenze kann nicht über der Fragenzahl liegen.',
        ]);

        $blueprints = collect($validated['blueprints'] ?? [])->filter(fn ($b) => ! empty($b['module_id']) && ! empty($b['question_count']));
        if ($blueprints->pluck('module_id')->duplicates()->isNotEmpty()) {
            return back()->withErrors(['blueprints' => 'Jedes Modul nur einmal im Fragenmix.'])->withInput();
        }
        if ($validated['status'] === 'published' && $blueprints->isEmpty()) {
            return back()->withErrors(['blueprints' => 'Ein veröffentlichtes Regelwerk braucht mindestens ein Modul im Fragenmix.'])->withInput();
        }
        $blueprintModuleCodes = Module::whereIn('id', $blueprints->pluck('module_id'))->pluck('code');
        $parts = collect($validated['parts'] ?? [])->filter(fn ($p) => trim((string) ($p['label'] ?? '')) !== '');
        foreach ($parts as $part) {
            if (empty($part['module']) || ! isset($part['min_correct'], $part['of']) || $part['min_correct'] > $part['of']) {
                return back()->withErrors(['parts' => "Prüfungsteil „{$part['label']}“: Modul sowie „x von y“ angeben (x höchstens y)."])->withInput();
            }
            // Ein Teil ohne Fragen im Bogen gilt als nicht bestanden -- dann könnte niemand bestehen.
            if (! $blueprintModuleCodes->contains($part['module'])) {
                return back()->withErrors(['parts' => "Prüfungsteil „{$part['label']}“: das Modul muss im Fragenmix vorkommen."])->withInput();
            }
        }

        $before = ['time_limit_seconds' => $ruleSet->time_limit_seconds, 'passing_rule' => $ruleSet->passing_rule, 'status' => $ruleSet->status, 'verified' => $ruleSet->verified_at !== null,
            'blueprints' => $ruleSet->blueprints()->with('module:id,code')->get()->map(fn ($b) => [$b->module?->code, $b->question_count])->all()];

        $passingRule = array_filter([
            'min_correct_ratio' => $validated['min_correct'] / $validated['of'],
            'parts' => $parts->map(fn ($p) => ['label' => trim($p['label']), 'module' => $p['module'], 'min_correct' => (int) $p['min_correct'], 'of' => (int) $p['of']])->values()->all() ?: null,
            'note' => trim((string) ($validated['note'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $verified = $request->boolean('verified');
        DB::transaction(function () use ($ruleSet, $validated, $passingRule, $verified, $blueprints, $request) {
            $ruleSet->update([
                'time_limit_seconds' => $validated['time_limit_minutes'] * 60,
                'passing_rule' => $passingRule,
                'status' => $validated['status'],
                'verified_at' => $verified ? ($ruleSet->verified_at ?? now()) : null,
                'verified_by' => $verified ? ($ruleSet->verified_by ?? $request->user()->id) : null,
            ]);
            $ruleSet->blueprints()->whereNotIn('module_id', $blueprints->pluck('module_id'))->delete();
            foreach ($blueprints as $blueprint) {
                ExamBlueprint::updateOrCreate(['rule_set_id' => $ruleSet->id, 'module_id' => $blueprint['module_id']], ['question_count' => (int) $blueprint['question_count']]);
            }
        });

        AuditLog::record(null, $request->user()->id, 'superadmin.exam_rule_set.update', 'exam_rule_set', $ruleSet->id, $before, [
            'time_limit_seconds' => $ruleSet->time_limit_seconds, 'passing_rule' => $passingRule, 'status' => $ruleSet->status, 'verified' => $verified,
            'blueprints' => $ruleSet->blueprints()->with('module:id,code')->get()->map(fn ($b) => [$b->module?->code, $b->question_count])->all(),
        ]);

        return redirect()->route('superadmin.exam-rules.index', $ruleSet->course_id)->with('status', 'Prüfungsregeln gespeichert.');
    }
}
