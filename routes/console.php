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
Schedule::command('app:prune-personal-data')->daily();

// Bug-Review B15: ohne diesen Lauf blieb entitlement.status für
// abgelaufene Freischaltungen dauerhaft auf "active" stehen.
Schedule::command('app:expire-entitlements')->hourly();
