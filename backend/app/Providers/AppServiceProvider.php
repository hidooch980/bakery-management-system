<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // migrate:fresh, migrate:refresh and db:wipe are refused outright in
        // production. The shop's whole history lives in this database and
        // there is no reason any of them should ever run against it — the
        // one time it happened, it took a `--force` and a cleared config
        // cache pointing the test suite at the real database, and the day's
        // work went with it.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Persian typography + RTL for the admin panel, injected via render hooks
        // so no core Filament view has to be overridden.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Blade::render('@include("filament.partials.rtl")'),
        );
    }
}
