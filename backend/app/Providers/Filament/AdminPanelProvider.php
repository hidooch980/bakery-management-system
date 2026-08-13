<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Short, because it sits in the sidebar beside every page and
            // a full sentence there is a sentence read a hundred times a day.
            ->brandName('نانوایی')
            // Iron and ember, the same hexes the phone app is built from
            // rather than Filament's generic ramps. Staff move between the
            // two all day; when the panel's amber and the app's are almost
            // but not quite the same colour, the pair reads as two products.
            //
            // Primary is the hot end of the ramp and warning the step below
            // it, which is deliberate: on this palette "needs attention" is
            // literally cooler than "do this", and the two are read as
            // positions on one scale rather than as separate colours.
            ->colors([
                'primary' => Color::hex('#E8952D'),  // ember, hot
                'gray' => Color::hex('#6B7684'),     // iron-biased, not blue
                'success' => Color::hex('#0B7A54'),
                'warning' => Color::hex('#C24A16'),  // ember, warm
                'danger' => Color::hex('#C5373C'),
                'info' => Color::hex('#2C6FA8'),
            ])
            ->font('Vazirmatn')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.kiln-theme')->render(),
            )
            ->darkMode(true)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            // Shop settings sit at the top: the formula, bag weight and
            // currency there drive every other screen's numbers. Only the
            // two groups used every day start open; the rest collapse so
            // the sidebar isn't a long wall of links on first glance.
            ->navigationGroups([
                NavigationGroup::make('تنظیمات'),
                NavigationGroup::make('تولید و فروش'),
                NavigationGroup::make('انبار')->collapsed(),
                NavigationGroup::make('امور مالی')->collapsed(),
                NavigationGroup::make('کارکنان')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // The curated Dashboard below lives in this same directory, so
            // discovery picks it up — no need to also register it by hand.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
