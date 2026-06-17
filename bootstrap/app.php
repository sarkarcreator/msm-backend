<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Http\Middleware\SecurityHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\HospitalAuditIntegrityCommand::class,
        \App\Console\Commands\SystemAuditIntegrityCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Add CORS handling for API routes
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
        
        // Add security headers to all responses
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
