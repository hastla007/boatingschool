<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $constraints = DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'course_file'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) ILIKE '%required_capability%'
        SQL);

        foreach ($constraints as $constraint) {
            DB::statement('ALTER TABLE course_file DROP CONSTRAINT '.DB::getQueryGrammar()->wrap($constraint->conname));
        }

        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_required_capability_check
            CHECK (required_capability IS NULL OR required_capability IN ('smarttrainer','favorites','exam','video','navigation','praxis','funk','downloads'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE course_file DROP CONSTRAINT IF EXISTS course_file_required_capability_check');
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_required_capability_check
            CHECK (required_capability IS NULL OR required_capability IN ('smarttrainer','favorites','exam','video','navigation','praxis','funk'))");
    }
};
