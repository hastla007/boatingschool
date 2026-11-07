<?php

use App\Support\ProductionReadiness;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
Schedule::command('app:prune-personal-data')->daily()->onOneServer()->withoutOverlapping(180);

// Bug-Review B15: ohne diesen Lauf blieb entitlement.status für
// abgelaufene Freischaltungen dauerhaft auf "active" stehen.
Schedule::command('app:expire-entitlements')->hourly()->onOneServer()->withoutOverlapping(55);
// Erinnerung vor dem Ablauf eines Kurszugangs (einmal je Ablaufdatum).
Schedule::command('app:remind-expiring-entitlements')->dailyAt('09:00')->timezone('Europe/Berlin')->onOneServer()->withoutOverlapping(180);

// Bug-Review B32: ohne diesen Lauf blieb exam_session.status für
// abgebrochene (nie wieder aufgerufene) Prüfungen dauerhaft auf "running"
// stehen.
Schedule::command('app:expire-abandoned-exam-sessions')->hourly()->onOneServer()->withoutOverlapping(55);

// Go-live B2: tägliche Datensicherung (Datenbank und Uploads, siehe config/backup.php).
Schedule::command('app:backup')->dailyAt('03:15')->timezone('Europe/Berlin')->onOneServer()->withoutOverlapping(180);

// Go-live B5: Lebenszeichen des Schedulers für app:check-production.
Schedule::call(fn () => Cache::forever(ProductionReadiness::SCHEDULER_HEARTBEAT_KEY, now()->timestamp))
    ->everyMinute()->name('ops:scheduler-heartbeat')->onOneServer();
