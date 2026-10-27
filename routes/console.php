<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// DSGVO-Review D3: löscht nur dann tatsächlich Daten, wenn die jeweilige
// Frist bewusst per .env aktiviert wurde (siehe config/data_retention.php).
// Ein echter Scheduler-Cronjob (`php artisan schedule:run` jede Minute)
// muss serverseitig eingerichtet sein, damit das überhaupt greift.
//
// Bug-Review (Scheduler ohne onOneServer): keiner der drei Tasks hatte
// bisher irgendeinen Schutz gegen Mehrfachlauf -- nicht mal
// withoutOverlapping() gegen den eigenen, überlappenden nächsten Lauf auf
// demselben Server, geschweige denn gegen einen zweiten, parallel
// laufenden Server (README dokumentiert aktuell nur einen einzelnen
// Cron-Eintrag, aber onOneServer() ist billige Absicherung gegen eine
// künftige horizontale Skalierung). Der default-Cache-Store ("database",
// siehe config/cache.php) unterstützt die dafür nötigen atomaren Locks.
Schedule::command('app:prune-personal-data')->daily()->onOneServer();

// Bug-Review B15: ohne diesen Lauf blieb entitlement.status für
// abgelaufene Freischaltungen dauerhaft auf "active" stehen.
Schedule::command('app:expire-entitlements')->hourly()->onOneServer();

// Bug-Review B32: ohne diesen Lauf blieb exam_session.status für
// abgebrochene (nie wieder aufgerufene) Prüfungen dauerhaft auf "running"
// stehen.
Schedule::command('app:expire-abandoned-exam-sessions')->hourly()->onOneServer();
