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

        $middleware->alias([
            'tenant.role' => \App\Http\Middleware\EnsureTenantRole::class,
            'tenant.member' => \App\Http\Middleware\EnsureTenantMembership::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
