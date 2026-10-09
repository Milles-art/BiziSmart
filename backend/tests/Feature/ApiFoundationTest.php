<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

it('returns liveness in the contract envelope without querying tenant data', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')->assertJsonPath('data.service', 'BiziSmart API');
    expect($response->json('request_id'))->toMatch('/^req_[0-9A-HJKMNP-TV-Z]{26}$/');
    $response->assertHeader('X-Request-Id', $response->json('request_id'));
});

it('keeps cookie authentication and test probes unpublished and requires a shop host for login', function () {
    expect(Route::has('auth.login'))->toBeTrue();
    expect(Route::has('storage.local'))->toBeFalse()->and(Route::has('storage.local.upload'))->toBeFalse();
    $this->get('/sanctum/csrf-cookie')->assertNotFound();
    $this->postJson('/api/v1/auth/login', [])->assertNotFound();
    $this->getJson('/api/v1/tenant-test/branches')->assertNotFound();
});

it('generates distinct request ids rather than accepting a client supplied value', function () {
    $first = $this->withHeader('X-Request-Id', 'client-input')->getJson('/api/v1/health');
    $second = $this->getJson('/api/v1/health');

    expect($first->json('request_id'))->not->toBe('client-input')
        ->not->toBe($second->json('request_id'));
});

it('returns JSON for unknown API routes even when the browser asks for HTML', function () {
    $response = $this->get('/api/v1/missing', ['Accept' => 'text/html']);

    $response->assertNotFound()->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertHeader('X-Request-Id', $response->json('request_id'));
});

it('returns an authentication envelope rather than trying to resolve an HTML login route', function () {
    Route::middleware('auth:sanctum')->get('/api/v1/test-browser-auth', fn () => ['unreachable' => true]);
    $this->get('/api/v1/test-browser-auth', ['Accept' => 'text/html'])->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHORIZED')->assertJsonMissingPath('trace');
});

it('preserves field validation errors without echoing submitted sensitive input', function () {
    Route::post('/api/v1/test-validation', fn (Request $request) => $request->validate([
        'pin' => ['required', 'digits:4'],
    ]));

    $this->postJson('/api/v1/test-validation', ['pin' => 'sensitive-value'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['pin']]], 'request_id'])
        ->assertDontSee('sensitive-value');
});

it('maps authentication, permission and method errors', function (string $route, int $status, string $code) {
    Route::get('/api/v1/test-auth', fn () => throw new AuthenticationException);
    Route::get('/api/v1/test-forbidden', fn () => abort(403, 'Do not expose this message'));

    $response = $route === '/api/v1/health'
        ? $this->postJson($route)
        : $this->getJson($route);

    $response->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    ['/api/v1/test-auth', 401, 'UNAUTHORIZED'],
    ['/api/v1/test-forbidden', 403, 'PERMISSION_DENIED'],
    ['/api/v1/health', 405, 'METHOD_NOT_ALLOWED'],
]);

it('keeps rate limit retry headers', function () {
    Route::get('/api/v1/test-rate', fn () => throw new TooManyRequestsHttpException(30));

    $this->getJson('/api/v1/test-rate')->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After', '30');
});

it('hides unexpected exception details even in debug mode', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-error', fn () => throw new RuntimeException('Internal database credentials'));

    $this->getJson('/api/v1/test-error')->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR')->assertDontSee('credentials')
        ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
});

it('renders domain exceptions with safe structured details', function () {
    Route::get('/api/v1/test-domain', fn () => throw new ApiException(
        'INSUFFICIENT_STOCK', 'Only 3 units available.', 400, 'items.0.qty', ['available' => 3],
    ));

    $this->getJson('/api/v1/test-domain')->assertStatus(400)
        ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('error.field', 'items.0.qty')->assertJsonPath('error.details.available', 3);
});
