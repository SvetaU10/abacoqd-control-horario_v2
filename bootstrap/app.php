<?php

use App\Http\Middleware\EnsureInstallationAccessIsOpen;
use App\Http\Middleware\EnsureJefa;
use App\Http\Middleware\EnsureTecnico;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventIndexing;
use App\Http\Middleware\ProtectFormRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PreventIndexing::class);
        $middleware->web(replace: [PreventRequestForgery::class => ProtectFormRequests::class]);
        $middleware->web(append: [EnsureInstallationAccessIsOpen::class]);
        $middleware->alias([
            'activo' => EnsureUserIsActive::class,
            'jefa' => EnsureJefa::class,
            'tecnico' => EnsureTecnico::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()?->paginaInicio() ?? 'jornada'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
