<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AdminOverview;
use App\Filament\Widgets\ModerationStats;
use App\Filament\Widgets\SiteHealthWidget;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireAdmin;
use Filament\Actions\Action;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->brandName('Second Hand')
            ->brandLogo(fn () => view('filament.components.brand'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode(false)
            ->colors([
                'primary' => Color::hex('#0066cc'),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.components.marketplace-link'))
            ->navigationItems([
                NavigationItem::make('View marketplace')->icon('heroicon-o-arrow-top-right-on-square')->url(fn () => url('/'))->sort(100),
            ])
            ->userMenuItems([
                Action::make('marketplace')->label('View marketplace')->icon('heroicon-o-arrow-top-right-on-square')->url(fn () => url('/')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AdminOverview::class,
                ModerationStats::class,
                SiteHealthWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                EnsureActiveUser::class,
                RequireAdmin::class,
            ], isPersistent: true);
    }
}
