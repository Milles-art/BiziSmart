<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API authentication errors stay JSON even when opened directly in a browser.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        // Exact catalogue wire values must reach validation without silent whitespace normalisation.
        $middleware->trimStrings(except: ['reorder_level', 'base_qty', 'barcode', 'qty', 'qty_change', 'expected_qty']);
        $middleware->alias(['permission' => \App\Http\Middleware\RequirePermission::class,
            'staff.session' => \App\Http\Middleware\RequireStaffSession::class,
            'enterprise.token' => \App\Http\Middleware\RequireEnterpriseToken::class,
            'owner' => \App\Http\Middleware\RequireOwner::class, 'auth.no_store' => \App\Http\Middleware\NoStoreAuth::class]);
        $middleware->prepend(AssignRequestId::class);
        // Livewire uses the web session middleware before panel-persistent middleware.
        $middleware->prepend(\App\Http\Middleware\ConfigureAdminSession::class);
        $middleware->appendToGroup('api', ResolveTenant::class);
        $middleware->prependToPriorityList([
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ], ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            return app(ApiExceptionRenderer::class)->render($exception, $request);
        });

        $exceptions->dontFlash(['pin', 'new_pin', 'password', 'password_confirmation', 'token', 'challenge_token', 'code', 'setup_secret', 'invitation_token', 'approval_token']);
    })->create();
