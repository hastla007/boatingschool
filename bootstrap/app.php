<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\EnforceMaintenanceMode::class,
            \App\Http\Middleware\ResolveTenant::class,
        ]);

        // Muss nach StartSession (Dev-Override liest die Session) aber vor
        // SubstituteBindings laufen: Route-Model-Binding für tenant-gebundene
        // Modelle (CourseDefinition, ExamSession, ...) braucht den bereits
        // aufgelösten Tenant-Kontext für RLS und den globalen Scope.
        $middleware->appendToPriorityList(
            after: \Illuminate\Session\Middleware\StartSession::class,
            append: \App\Http\Middleware\ResolveTenant::class,
        );

        // EnsureSuperAdmin (der "superadmin"-Alias) muss nach Authenticate
        // laufen (braucht $request->user()) und VOR UseAdminDatabaseConnection
        // -- sonst würde admin-db die DB-Verbindung schon für nicht
        // angemeldete oder nicht berechtigte Requests auf /superadmin/*
        // umschalten, bevor der eigentliche Zugriffsschutz überhaupt greift
        // (Sicherheitsreview N10: admin-db stand vorher noch vor
        // Authenticate in der Default-Priorität).
        $middleware->appendToPriorityList(
            after: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            append: \App\Http\Middleware\EnsureSuperAdmin::class,
        );

        // Muss weiterhin vor SubstituteBindings laufen: Route-Model-Binding
        // für Superadmin-Routen (Tenant, Module, ContentQuestion, ...) muss
        // bereits auf der RLS-freien Owner-Verbindung hydriert werden --
        // sonst "frieren" die gebundenen Models auf der App-Verbindung ein
        // (Eloquent setzt die Connection beim Hydrieren fest), was zu
        // unsichtbaren RLS-gefilterten Relationen und Foreign-Key-Fehlern
        // bei Schreibzugriffen aus derselben Transaktion führt. Jetzt erst
        // NACH EnsureSuperAdmin, nicht mehr direkt nach ResolveTenant.
        $middleware->appendToPriorityList(
            after: \App\Http\Middleware\EnsureSuperAdmin::class,
            append: \App\Http\Middleware\UseAdminDatabaseConnection::class,
        );

        $middleware->alias([
            'tenant.role' => \App\Http\Middleware\EnsureTenantRole::class,
            'tenant.member' => \App\Http\Middleware\EnsureTenantMembership::class,
            'superadmin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'admin-db' => \App\Http\Middleware\UseAdminDatabaseConnection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
