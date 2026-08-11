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
