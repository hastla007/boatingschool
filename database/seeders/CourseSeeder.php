<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Module;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Die elf globalen Kursdefinitionen laut "Course Logic"-Blatt der
     * zentralen Fragenbasis. Global = tenant_id NULL: jede Bootsschule kann
     * daraus ihre Angebote zusammenstellen, ohne Content zu duplizieren.
     */
    public function run(): void
    {
        $courses = [
            ['code' => 'SBF-SEE', 'name' => 'SBF See', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_SEE']],
            ['code' => 'SBF-BIN-MOTOR', 'name' => 'SBF Binnen', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN']],
            ['code' => 'SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN']],
            ['code' => 'ADD-SEE-TO-BIN', 'name' => 'Ergänzung See → Binnen', 'type' => 'addon', 'modules' => ['SBF_BINNEN']],
            ['code' => 'ADD-BIN-TO-SEE', 'name' => 'Ergänzung Binnen → See', 'type' => 'addon', 'modules' => ['SBF_SEE']],
            ['code' => 'ADD-BIN-SEGEL', 'name' => 'Ergänzung Binnen Segeln', 'type' => 'addon', 'modules' => ['SBF_BINNEN_SEGELN']],
            ['code' => 'SRC', 'name' => 'SRC Vollkurs', 'type' => 'full', 'modules' => ['SRC']],
            ['code' => 'UBI', 'name' => 'UBI Vollkurs', 'type' => 'full', 'modules' => ['UBI']],
            ['code' => 'SRC-UBI', 'name' => 'SRC & UBI Kombikurs', 'type' => 'combo', 'modules' => ['SRC', 'UBI']],
            ['code' => 'ADD-UBI-TO-SRC', 'name' => 'UBI-Ergänzung zu SRC', 'type' => 'addon', 'modules' => ['UBI']],
            ['code' => 'ADD-SRC-TO-UBI', 'name' => 'SRC-Ergänzung zu UBI', 'type' => 'addon', 'modules' => ['SRC']],
        ];

        foreach ($courses as $definition) {
            $course = CourseDefinition::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => null, 'code' => $definition['code']],
                ['name' => $definition['name'], 'course_type' => $definition['type'], 'status' => 'published']
            );

            foreach (array_values($definition['modules']) as $index => $moduleCode) {
                $module = Module::where('code', $moduleCode)->firstOrFail();
                $course->modules()->syncWithoutDetaching([
                    $module->id => ['sort_order' => $index + 1, 'required' => true],
                ]);
            }
        }
    }
}
