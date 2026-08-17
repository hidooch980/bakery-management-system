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
            // The same hexes the phone app is built from rather than
            // Filament's generic ramps. Staff move between the two all day;
            // when the panel's amber and the app's are almost but not quite
            // the same colour, the pair reads as two products.
            //
            // Primary and warning are two different colours here, not two
            // steps of one ramp — that was the old palette's idea and it
            // was wrong. The yellow means «press this» and the orange means
            // «read this», and a person has to tell them apart at a glance
            // from across a bakery. On one ramp they cannot.
            ->colors([
                'primary' => Color::hex('#F5C518'),  // the signal — «press this»
                'gray' => Color::hex('#6E7278'),     // neutral-biased, not blue
                'success' => Color::hex('#2E9E6B'),  // money in
                'warning' => Color::hex('#C2691C'),  // attention — «read this»
                'danger' => Color::hex('#D1495B'),   // money out
                'info' => Color::hex('#3B82C4'),
            ])
            ->font('Vazirmatn')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.panel-ground')->render(),
            )
            ->darkMode(true)
            // Fully, not to a strip of icons. Collapsed to icons the menu
            // still holds its column and the reports beside it still wrap;
            // gone, the tables get the whole width, which is what the wide
            // ones — bank statement, sales, payroll — were short of. It
            // reopens over the page and closes again, and remembers which
            // way the owner left it.
            ->sidebarFullyCollapsibleOnDesktop()
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
