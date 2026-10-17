<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\PanelProvider;
class AdminPanelProvider extends PanelProvider {
    public function panel(Panel $panel):Panel {
        return $panel->default()->id('admin')->domain(config('platform.admin_domain'))->path('')->login(\App\Filament\Auth\AdminLogin::class)->profile(\App\Filament\Auth\AdminProfile::class)->authGuard('admin')->authPasswordBroker('admins')->brandName('BiziSmart Administration')
            ->colors(['primary'=>\Filament\Support\Colors\Color::Emerald])
            ->passwordReset(\App\Filament\Auth\RequestAdminPasswordReset::class, \App\Filament\Auth\ResetAdminPassword::class)
            ->pages([\App\Filament\Pages\PlatformManagement::class])
            // Livewire's web route already decrypts cookies and starts the session.
            // Reapplying these to its fake original request rotates the session ID
            // and can lose database-backed sessions. Persist authorization only.
            ->middleware([\App\Http\Middleware\ConfigureAdminSession::class,\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,\Illuminate\Session\Middleware\AuthenticateSession::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\Illuminate\Routing\Middleware\SubstituteBindings::class,\Filament\Http\Middleware\DisableBladeIconComponents::class,\Filament\Http\Middleware\DispatchServingFilamentEvent::class])
            ->middleware([\Illuminate\Session\Middleware\AuthenticateSession::class,\App\Http\Middleware\AdminSessionSecurity::class],isPersistent:true)
            ->authMiddleware([\Filament\Http\Middleware\Authenticate::class],isPersistent:true);
    }
}
