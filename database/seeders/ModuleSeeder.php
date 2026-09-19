<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Die sechs zentralen Inhaltsmodule laut Umsetzungskonzept, aus denen
     * sich alle elf Kursprodukte zusammensetzen.
     */
    public function run(): void
    {
        $modules = [
            ['code' => 'SBF_BASIS', 'name' => 'SBF Basis (gemeinsame Grundlagen)'],
            ['code' => 'SBF_SEE', 'name' => 'SBF See (spezifisch)'],
            ['code' => 'SBF_BINNEN', 'name' => 'SBF Binnen (spezifisch)'],
            ['code' => 'SBF_BINNEN_SEGELN', 'name' => 'SBF Binnen Segeln (Zusatzmodul)'],
            ['code' => 'SRC', 'name' => 'SRC Seefunk'],
            ['code' => 'UBI', 'name' => 'UBI Binnenfunk'],
            ['code' => 'SKN', 'name' => 'SKN'],
            ['code' => 'FKN', 'name' => 'FKN'],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['code' => $module['code']],
                ['name' => $module['name'], 'status' => 'published']
            );
        }
    }
}
