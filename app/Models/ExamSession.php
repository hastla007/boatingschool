<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\ExamEvaluationService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExamSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'exam_session';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'rule_set_id', 'paper_id', 'status',
        'started_at', 'submitted_at', 'score', 'passed', 'rule_snapshot',
        'navigation_task_id', 'navigation_submitted_at', 'navigation_points', 'navigation_task_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'rule_snapshot' => 'array',
            'navigation_task_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
            'score' => 'float',
            'passed' => 'boolean',
            'navigation_submitted_at' => 'datetime',
            'navigation_points' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ExamRuleSet::class, 'rule_set_id');
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    public function navigationTask(): BelongsTo
    {
        return $this->belongsTo(NavigationTask::class, 'navigation_task_id');
    }

    public function navigationAnswers(): HasMany
    {
        return $this->hasMany(ExamSessionNavigationAnswer::class, 'exam_session_id')->orderBy('position');
    }

    /** Gehört zu dieser Prüfung eine Navigationsaufgabe mit eigenem Mindestwert? */
    public function hasNavigationPart(): bool
    {
        // Auch wenn die Aufgabe inzwischen gelöscht wurde: die festgehaltenen
        // Antworten bleiben Teil der Prüfung.
        return $this->effectiveRuleSet()->navigationRule() !== null
            && ($this->navigation_task_id !== null || $this->navigationAnswers()->exists());
    }

    /** Nachfrist für die Abgabe der Navigation bzw. die Selbsteinschätzung nach Zeitablauf (Sekunden). */
    public const NAVIGATION_SUBMIT_GRACE = 120;

    public const NAVIGATION_ASSESS_GRACE = 900;

    /** Review-Runde 11: Navigationsaufgabe im Stand des Prüfungsstarts (Text, Hinweis, Karte). */
    public function navigationTaskAsStarted(): ?NavigationTask
    {
        if ($this->navigation_task_snapshot) {
            $task = (new NavigationTask)->forceFill($this->navigation_task_snapshot + ['id' => $this->navigation_task_id]);
            // Review-Runde 12: die Karte kommt von der aktuellen Aufgabe -- eine
            // ersetzte Karte löscht die alte Datei, der Schnappschuss zeigte
            // sonst ins Leere.
            if ($live = $this->navigationTask) {
                $task->forceFill(['chart_image_path' => $live->chart_image_path, 'chart_caption' => $live->chart_caption]);
            }

            return $task;
        }

        return $this->navigationTask;
    }

    /**
     * Review-Runde 12: läuft der Versuch noch für den Teilnehmer -- auch nach
     * Ablauf der Prüfungszeit, solange die Navigation noch abgegeben (kurze
     * Nachfrist) oder selbst eingeschätzt wird. Ein Neustart des Bogens
     * wertete in diesem Fenster sonst den Versuch sofort aus.
     */
    public function stillOpen(bool $timeExpired): bool
    {
        if ($this->status !== 'running') {
            return false;
        }
        if (! $timeExpired || $this->inNavigationAssessment()) {
            return true;
        }
        $deadline = $this->deadline();

        return $deadline && $this->navigation_submitted_at === null && $this->hasNavigationPart()
            && now()->lte($deadline->copy()->addSeconds(self::NAVIGATION_SUBMIT_GRACE));
    }

    /**
     * Offene Prüfungen der Person in ALLEN Schulen (Review-Runde 13: die
     * Sperre der Prüfungsfragen im Lernmodus und der Navigationsaufgabe im
     * Übungsbereich galt nur in der aktuellen Schule -- mit derselben Kurs-
     * freischaltung bei einer zweiten Schule ließ sich dort die Lösung
     * abrufen). Über die Owner-Verbindung, da RLS andere Schulen ausblendet;
     * „offen“ heißt wie überall stillOpen() (inkl. Nachfrist und
     * Selbsteinschätzung der Navigation).
     *
     * @return Collection<int, self>
     */
    public static function openForUserAcrossTenants(string $userId): Collection
    {
        $evaluation = app(ExamEvaluationService::class);

        return static::on('pgsql_admin')->withoutGlobalScopes()->where('user_id', $userId)->where('status', 'running')
            ->with('ruleSet')->get()
            ->filter(fn (self $s) => $s->stillOpen($evaluation->timeExpired($s)))->values();
    }

    /** Fragen-IDs aller offenen Prüfungen der Person (schulübergreifend). @return Collection<int, string> */
    public static function blockedQuestionIdsFor(string $userId): Collection
    {
        $ids = static::openForUserAcrossTenants($userId)->pluck('id');

        return $ids->isEmpty() ? collect() : DB::connection('pgsql_admin')->table('exam_session_question')
            ->whereIn('exam_session_id', $ids)->pluck('question_id')->unique()->values();
    }

    /**
     * Laufende Prüfung, in der diese Navigationsaufgabe gerade dran ist --
     * deren Lösungen bleiben im Übungsbereich so lange gesperrt (Review-Runde
     * 10/11: auch während der Nachfrist und der Selbsteinschätzung; Runde 13:
     * schulübergreifend). $tenantId bleibt für die Aufrufer erhalten, die
     * auf eine Prüfung derselben Schule weiterleiten.
     */
    public static function runningWithNavigationTask(string $tenantId, string $userId, string $taskId): ?self
    {
        return static::openForUserAcrossTenants($userId)
            ->filter(fn (self $s) => $s->navigation_task_id === $taskId)
            ->sortByDesc(fn (self $s) => $s->tenant_id === $tenantId)
            ->first();
    }

    public function deadline(): ?Carbon
    {
        $limit = $this->effectiveRuleSet()->time_limit_seconds;

        return $limit ? $this->started_at->copy()->addSeconds($limit) : null;
    }

    /** Navigation abgegeben, Selbsteinschätzung läuft noch (zählt nicht zur Prüfungszeit). */
    public function inNavigationAssessment(): bool
    {
        $deadline = $this->deadline();

        return $this->status === 'running' && $this->navigation_submitted_at !== null && $this->hasNavigationPart()
            && (! $deadline || now()->lte($deadline->copy()->addSeconds(self::NAVIGATION_ASSESS_GRACE)));
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamSessionQuestion::class, 'exam_session_id')->orderBy('position');
    }

    /** Regeln zum Zeitpunkt des Starts (für das Festhalten in rule_snapshot). */
    public static function snapshotOf(ExamRuleSet $ruleSet): array
    {
        return ['time_limit_seconds' => $ruleSet->time_limit_seconds, 'passing_rule' => $ruleSet->passing_rule];
    }

    /**
     * Das Regelwerk, wie es beim Start galt: spätere Änderungen durch den
     * Superadmin wirken nicht auf laufende oder bewertete Prüfungen zurück.
     * Ohne Schnappschuss (ältere Sessions) das aktuelle Regelwerk.
     */
    public function effectiveRuleSet(): ExamRuleSet
    {
        $ruleSet = $this->ruleSet;
        if (! is_array($this->rule_snapshot)) {
            return $ruleSet;
        }

        return tap(clone $ruleSet, function (ExamRuleSet $copy) {
            $copy->time_limit_seconds = $this->rule_snapshot['time_limit_seconds'] ?? null;
            $copy->passing_rule = $this->rule_snapshot['passing_rule'] ?? null;
        });
    }
}
