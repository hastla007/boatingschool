<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
            return (new NavigationTask)->forceFill($this->navigation_task_snapshot + ['id' => $this->navigation_task_id]);
        }

        return $this->navigationTask;
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
