<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // After TrustProxies, so HTTPS behind a load balancer is detected.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never keep a manager's approval password or SC/PWD details in the
        // session after a failed form (sessions are stored in the database; D-183).
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'discount.approver.password', 'discount.beneficiary', 'approver_password',
        ]);

        // Friendly error pages without stack traces (NFR-020, D-183). Server
        // errors show Laravel's debug page only when APP_DEBUG is on.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($request->expectsJson() || ! in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }
            if ($status >= 500 && config('app.debug')) {
                return $response;
            }
            // An expired form (e.g. left open past the session timeout): back to it with a message.
            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('The page expired. Please try again.')]);

                return back();
            }

            return Inertia::render('errors/Show', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
