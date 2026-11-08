<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-Runde 10: Navigationsantworten einer Prüfung halten Frage und
 * Musterlösung zum Zeitpunkt des Starts fest. Ändert oder löscht der
 * Superadmin später eine Teilfrage, bleiben Bewertung und Ergebnisseite
 * unverändert (vorher löschte die Kaskade die Antworten mit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_navigation_answer', function (Blueprint $table) {
            $table->unsignedSmallInteger('question_number')->nullable();
            $table->text('question_text')->nullable();
            $table->text('answer_text')->nullable();
        });

        DB::statement('UPDATE exam_session_navigation_answer a SET question_number = q.question_number, question_text = q.question_text, answer_text = q.answer_text
            FROM navigation_question q WHERE q.id = a.navigation_question_id');

        Schema::table('exam_session_navigation_answer', function (Blueprint $table) {
            $table->dropForeign(['navigation_question_id']);
        });
        DB::statement('ALTER TABLE exam_session_navigation_answer ALTER COLUMN navigation_question_id DROP NOT NULL');
        Schema::table('exam_session_navigation_answer', function (Blueprint $table) {
            $table->foreign('navigation_question_id')->references('id')->on('navigation_question')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_navigation_answer', function (Blueprint $table) {
            $table->dropColumn(['question_number', 'question_text', 'answer_text']);
        });
    }
};
