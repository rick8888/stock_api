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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwt' => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'module' => \App\Http\Middleware\EnsureModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\DomainException $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 422;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        });

        $exceptions->render(function (\App\Exceptions\InvalidCredentialsException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Non authentifié.'], 401);
            }
        });
    })->create();
