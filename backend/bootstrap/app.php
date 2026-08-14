<?php

use App\Exceptions\InsufficientStockException;
use Filament\Notifications\Notification;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Where an unauthenticated visitor is sent. Laravel's default asks
        // for a route named `login`, which this app does not have — the
        // panel's is `filament.admin.auth.login` — and the guest redirect
        // is resolved *while building* the AuthenticationException, so it
        // threw before the handler below could turn it into a 401. Every
        // unauthenticated API request from anything that did not send
        // `Accept: application/json` got a 500 error page instead.
        //
        // Null for the API: there is nowhere to send a phone, and without
        // a redirect the exception reaches the renderer and comes back as
        // the JSON 401 the app knows how to handle.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Keep every API failure in the same envelope the controllers use.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                // The first real message rather than a fixed sentence. The
                // app shows `message` and nothing else, so replacing it
                // threw away the only useful part: a seller with the wrong
                // password was told the app had sent invalid data, which
                // reads as a broken app rather than a typo.
                $first = collect($e->errors())->flatten()->first();

                return response()->json([
                    'success' => false,
                    'message' => $first ?: 'اطلاعات ارسالی نامعتبر است.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'برای دسترسی باید وارد شوید.',
                    'errors' => null,
                ], 401);
            }
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای این عملیات را ندارید.',
                    'errors' => null,
                ], 403);
            }
        });

        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => null,
                ], 422);
            }

            // Running out of stock is an ordinary thing on a shop floor, so
            // the panel says so in words. Left unhandled it reached Laravel
            // as a Server Error page — which gives whoever hit it nothing
            // to act on and looks like the system broke.
            Notification::make()
                ->title('موجودی کافی نیست')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return back();
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'مورد درخواستی یافت نشد.',
                    'errors' => null,
                ], 404);
            }
        });
    })->create();
