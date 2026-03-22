<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;


/**
 * NiaLink Application Configuration
 * --------------------------------------------------------------------------
 * This file acts as the central injection point for the Laravel 11 framework.
 * It configures how the application handles routing, global middleware,
 * and custom exception rendering for the NiaLink ecosystem.
 */
return Application::configure(basePath: dirname(__DIR__))

    /**
     * 1. ROUTING CONFIGURATION
     * Defines the entry points for Web, API, and Console commands.
     */
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    /**
     * 2. MIDDLEWARE CONFIGURATION
     * Global and specific middleware stack setup (e.g., Sanctum, CSRF).
     */
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })


    /**
     * 3. EXCEPTION HANDLING [Security & UX]
     * Customizes how the application responds to errors.
     * Specifically handles the "429 Too Many Requests" response to ensure
     * a user-friendly message during API rate limiting/throttling.
     */
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {

        // Only apply custom JSON response if the request is an API call
        if ($request->is('api/*')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many attempts. For your security, please wait a moment before trying again.',
                'retry_after_seconds' => $e->getHeaders()['Retry-After'] ?? 60
            ], 429);
        }
    });
    })->create();
