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

        // Muss ebenfalls vor SubstituteBindings laufen: Route-Model-Binding
        // für Superadmin-Routen (Tenant, Module, ContentQuestion, ...) muss
        // bereits auf der RLS-freien Owner-Verbindung hydriert werden --
        // sonst "frieren" die gebundenen Models auf der App-Verbindung ein
        // (Eloquent setzt die Connection beim Hydrieren fest), was zu
        // unsichtbaren RLS-gefilterten Relationen und Foreign-Key-Fehlern
        // bei Schreibzugriffen aus derselben Transaktion führt.
        $middleware->appendToPriorityList(
            after: \App\Http\Middleware\ResolveTenant::class,
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
