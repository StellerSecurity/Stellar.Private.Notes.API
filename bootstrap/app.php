<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \App\Http\Middleware\MeasurePerformance::class,
            // Preserve legacy empty strings and whitespace in encrypted payloads.
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
