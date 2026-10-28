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
//
// Bug-Review (Scheduler läuft ohne withoutOverlapping): onOneServer()
// allein schützt nur gegen einen GLEICHZEITIGEN zweiten Server -- läuft
// ein Task auf DEMSELBEN Server länger als sein eigenes Intervall (z. B.
// eine ungewöhnlich lange Prune-/Expire-Abfrage bei viel Daten), startet
// der nächste geplante Lauf trotzdem parallel dazu. withoutOverlapping()
// verhindert genau das.
Schedule::command('app:prune-personal-data')->daily()->onOneServer()->withoutOverlapping();

// Bug-Review B15: ohne diesen Lauf blieb entitlement.status für
// abgelaufene Freischaltungen dauerhaft auf "active" stehen.
Schedule::command('app:expire-entitlements')->hourly()->onOneServer()->withoutOverlapping();

// Bug-Review B32: ohne diesen Lauf blieb exam_session.status für
// abgebrochene (nie wieder aufgerufene) Prüfungen dauerhaft auf "running"
// stehen.
Schedule::command('app:expire-abandoned-exam-sessions')->hourly()->onOneServer()->withoutOverlapping();
