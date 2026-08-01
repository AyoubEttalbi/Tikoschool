<?php

use Illuminate\Support\Facades\Route;

/*
 * The single highest-value test in this suite.
 *
 * Every route in this app operates on student, financial or staff data. The application
 * previously shipped a set of routes registered BELOW the closing brace of the
 * `Route::middleware('auth')->group(...)` block in routes/web.php — an easy mistake that is
 * invisible in review because the file still "looks" like everything is inside the group.
 *
 * Among them: a debug endpoint returning the whole session (including the CSRF token) to
 * anonymous callers, the daily cash register, and an invoice-delete route that reverses
 * teacher wallet payments.
 *
 * This test enumerates the real route table and fails if ANY route becomes reachable
 * without authentication unless it is on the explicit allowlist below.
 */

/** Routes that are legitimately public. Adding to this list should require justification. */
const PUBLIC_ROUTES = [
    '/',                       // redirects to /login or /dashboard
    'up',                      // health check
    'login',
    'logout',
    'forgot-password',
    'reset-password/{token}',
    'reset-password',
    'storage/{path}',          // local filesystem disk
    'sanctum/csrf-cookie',
    'broadcasting/auth',       // channel authorization; callbacks receive a null user
    'wasender/webhook',        // signed by WASENDERAPI_WEBHOOK_SECRET; fails closed
    '{fallbackPlaceholder}',   // catch-all redirect
];

test('no route is reachable without authentication', function () {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => in_array($route->uri(), PUBLIC_ROUTES, true))
        // debug-assistant is registered only when app()->environment('local')
        ->reject(fn ($route) => str_starts_with($route->uri(), 'debug-assistant'))
        ->filter(function ($route) {
            $middleware = $route->gatherMiddleware();

            return ! in_array('auth', $middleware, true)
                && ! in_array(\Illuminate\Auth\Middleware\Authenticate::class, $middleware, true);
        })
        ->map(fn ($route) => implode('|', $route->methods()) . ' ' . $route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe(
        [],
        "These routes are reachable ANONYMOUSLY:\n  " . implode("\n  ", $unprotected)
    );
});

test('no debug or diagnostic routes are registered', function () {
    $debugRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn ($uri) => str_contains($uri, 'debug') || str_contains($uri, 'test-'))
        // The one legitimate exception is environment-guarded.
        ->reject(fn ($uri) => str_starts_with($uri, 'debug-assistant'))
        ->values()
        ->all();

    expect($debugRoutes)->toBe(
        [],
        "Debug routes must not ship:\n  " . implode("\n  ", $debugRoutes)
    );
});

test('a route uri is never registered twice for the same method', function () {
    // A later duplicate registration silently REPLACES the earlier one, including its
    // middleware. That is how POST /admin/switch-back lost its auth + AdminMiddleware.
    $seen = [];
    $duplicates = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $key = $method . ' ' . $route->uri();
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;
        }
    }

    expect(array_unique($duplicates))->toBe([]);
});
