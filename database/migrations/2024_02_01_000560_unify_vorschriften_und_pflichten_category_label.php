<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Themen mal mit "und", mal mit "&"): SBF-See-Fragen trugen die
 * Smartmodus-Kategorie "Vorschriften und Pflichten", Binnen- und
 * Basisfragen das Thema "Vorschriften & Pflichten" -- im Kombikurs SBF See &
 * Binnen standen dadurch zwei fast gleich benannte Kacheln nebeneinander.
 * Die Quelle (zentrale_fragenbasis.xlsx) nutzt jetzt einheitlich
 * "Vorschriften & Pflichten"; diese Migration zieht die Bezeichnung auf
 * bestehenden Installationen nach. Reine Metadaten in allen Revisionen,
 * damit content:import sie als unverändert erkennt.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['topic', 'smartmodus_kategorie'] as $column) {
            DB::table('content_question_revision')
                ->where($column, 'Vorschriften und Pflichten')
                ->update([$column => 'Vorschriften & Pflichten']);
        }
    }

    public function down(): void {}
};
