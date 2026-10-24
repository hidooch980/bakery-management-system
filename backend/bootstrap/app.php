<?php

use App\Exceptions\AlreadyClaimedException;
use App\Exceptions\InsufficientStockException;
use App\Http\Middleware\EndsInactiveSessions;
use App\Http\Middleware\IdempotentWrites;
use App\Http\Middleware\PicksTheBakery;
use App\Http\Middleware\RecordsAppVersion;
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
        api: [__DIR__.'/../routes/api.php', __DIR__.'/../routes/seller_work.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => EndsInactiveSessions::class,
            'app-version' => RecordsAppVersion::class,
            'idempotent' => IdempotentWrites::class,
            'picks-bakery' => PicksTheBakery::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // مهمان پنل به ورود پنل می‌رود؛ درخواست اپ بدون انتقال، پاسخ ۴۰۱ می‌گیرد.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // همه خطاهای اپ با قالب یکسان برمی‌گردند.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                // اولین دلیل واقعی اعتبارسنجی به اپ می‌رسد تا علت خطا پنهان نشود.
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

        // ثبت هم‌زمانِ انجام‌شده پاسخ تعارض می‌گیرد، نه خطای ورودی.
        $exceptions->render(function (AlreadyClaimedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => null,
                ], 409);
            }

            Notification::make()
                ->title('این کار قبلاً انجام شده')
                ->body($e->getMessage())
                ->warning()
                ->persistent()
                ->send();

            return back();
        });

        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => null,
                ], 422);
            }

            // کمبود موجودی با پیام قابل فهم در پنل نمایش داده می‌شود.
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
