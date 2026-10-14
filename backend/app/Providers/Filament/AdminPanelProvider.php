<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use App\Filament\Auth\AdminAppAuthentication;
class AdminPanelProvider extends PanelProvider {
    public function panel(Panel $panel):Panel {
        return $panel->default()->id('admin')->domain(config('platform.admin_domain'))->path('')->login(\App\Filament\Auth\AdminLogin::class)->profile()->authGuard('admin')->brandName('BiziSmart Administration')
            ->multiFactorAuthentication([AdminAppAuthentication::make()->codeWindow(1)],isRequired:true)
            ->pages([\App\Filament\Pages\PlatformManagement::class])
            ->middleware([\App\Http\Middleware\ConfigureAdminSession::class,\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,\Illuminate\Session\Middleware\AuthenticateSession::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\Illuminate\Routing\Middleware\SubstituteBindings::class,\Filament\Http\Middleware\DisableBladeIconComponents::class,\Filament\Http\Middleware\DispatchServingFilamentEvent::class,\App\Http\Middleware\AdminSessionSecurity::class],isPersistent:true)
            ->authMiddleware([\Filament\Http\Middleware\Authenticate::class],isPersistent:true);
    }
}
