<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseDefinition extends Model
{
    use HasUuids;

    protected $table = 'course_definition';

    protected $fillable = ['tenant_id', 'code', 'name', 'course_type', 'status'];

    /**
     * Kurse sind entweder global (tenant_id NULL, Plattform-Katalog) oder
     * mandantenspezifisch. Der Scope spiegelt exakt die RLS-Policy
     * course_definition_isolation wider.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                $builder->where(function ($q) use ($context) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', $context->id());
                });
            }
        });
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'course_module', 'course_id', 'module_id')
            ->withPivot(['sort_order', 'required', 'rules'])
            ->orderByPivot('sort_order');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'course_id');
    }

    public function examRuleSets(): HasMany
    {
        return $this->hasMany(ExamRuleSet::class, 'course_id');
    }

    public function videoModules(): HasMany
    {
        return $this->hasMany(VideoModule::class, 'course_id')->orderBy('sort_order');
    }

    public function navigationTasks(): HasMany
    {
        return $this->hasMany(NavigationTask::class, 'course_id')->orderBy('sort_order');
    }

    public function examPapers(): HasMany
    {
        return $this->hasMany(ExamPaper::class, 'course_id')->orderBy('sort_order');
    }

    public function praxisTasks(): HasMany
    {
        return $this->hasMany(PraxisTask::class, 'course_id')->orderBy('sort_order');
    }

    /** Aktuell gültiges (published + verifiziert) Regelwerk. */
    public function activeExamRuleSet(): ?ExamRuleSet
    {
        return $this->examRuleSets()
            ->where('status', 'published')
            ->whereNotNull('verified_at')
            ->orderByDesc('valid_from')
            ->first();
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
