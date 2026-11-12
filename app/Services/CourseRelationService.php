<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/** Course hide/recommend rules matching the mobile catalogue in the WP plugin. */
class CourseRelationService
{
    private const RULES = [
        'SBF-BIN-MOTOR' => ['hide' => ['SBF-SEE-BIN', 'ADD-SEE-TO-BIN'], 'recommend' => ['ADD-BIN-TO-SEE', 'ADD-BIN-SEGEL']],
        'SBF-SEE' => ['hide' => ['SBF-SEE-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-SEE-TO-BIN']],
        'SBF-SEE-BIN' => ['hide' => ['SBF-SEE', 'SBF-BIN-MOTOR', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-BIN-SEGEL']],
        'SBF-BIN-SEGEL' => ['hide' => ['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-BIN-SEGEL', 'ADD-SEE-TO-BIN'], 'recommend' => ['ADD-BIN-TO-SEE']],
        'ADD-BIN-TO-SEE' => ['hide' => ['SBF-SEE', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN'], 'recommend' => []],
        'ADD-SEE-TO-BIN' => ['hide' => ['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-BIN-SEGEL']],
        'ADD-BIN-SEGEL' => ['hide' => ['SBF-BIN-SEGEL'], 'recommend' => []],
        'SRC' => ['hide' => ['SRC-UBI'], 'recommend' => ['ADD-UBI-TO-SRC']],
        'UBI' => ['hide' => ['SRC-UBI', 'ADD-UBI-TO-SRC'], 'recommend' => ['SRC']],
        'SRC-UBI' => ['hide' => ['SRC', 'UBI', 'ADD-UBI-TO-SRC'], 'recommend' => []],
        'ADD-UBI-TO-SRC' => ['hide' => ['UBI', 'SRC-UBI'], 'recommend' => []],
        'FKN' => ['hide' => [], 'recommend' => ['ADD-FKN-TO-SKN']],
        'SKN' => ['hide' => ['ADD-FKN-TO-SKN'], 'recommend' => []],
    ];

    public function __construct(private EntitlementService $entitlements) {}

    /**
     * @param  Collection<int, CourseDefinition>  $courses
     * @return array<string, array{hidden:bool,recommended:bool,recommended_for:list<array{id:string,name:string}>}>
     */
    public function forCourses(Tenant $tenant, User $user, Collection $courses): array
    {
        $all = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('status', 'published')->get()->keyBy('code');
        $owned = $this->entitlements->activeEntitlements($tenant, $user)
            ->filter(fn ($e) => $e->capabilities === null || in_array('smarttrainer', $e->capabilities, true))
            ->map(fn ($e) => $e->course?->code)->filter()->unique()->values()->all();
        $ownedSet = array_fill_keys($owned, true);
        $hide = [];
        $recommend = [];
        $triggers = array_values(array_unique(array_merge(array_values(array_intersect($courses->pluck('code')->all(), $owned)), $owned)));
        foreach ($triggers as $code) {
            foreach (self::RULES[$code]['hide'] ?? [] as $target) {
                $hide[$target] = true;
            }
            foreach (self::RULES[$code]['recommend'] ?? [] as $target) {
                $recommend[$target][] = $code;
            }
        }

        $out = [];
        foreach ($courses as $course) {
            $code = (string) $course->code;
            $ownsQuestionBank = isset($ownedSet[$code]);
            $hidden = ! $ownsQuestionBank && isset($hide[$code]);
            $recommendedFor = [];
            if (! $ownsQuestionBank && ! $hidden) {
                foreach (array_values(array_unique($recommend[$code] ?? [])) as $source) {
                    if (isset($all[$source])) {
                        $recommendedFor[] = ['id' => $all[$source]->id, 'name' => $all[$source]->name];
                    }
                }
            }
            $out[$code] = ['hidden' => $hidden, 'recommended' => $recommendedFor !== [], 'recommended_for' => $recommendedFor];
        }

        return $out;
    }
}
