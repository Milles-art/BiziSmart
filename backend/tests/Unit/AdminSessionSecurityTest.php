<?php
use App\Http\Middleware\AdminSessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
uses(Tests\TestCase::class);
it('requires fresh password and MFA after idle expiry remembered recall or administrator revocation',function(bool $active,bool $remembered,int $idle){
    $guard=Mockery::mock();$guard->shouldReceive('check')->andReturn(true);$guard->shouldReceive('user')->andReturn((object)['is_active'=>$active]);$guard->shouldReceive('viaRemember')->andReturn($remembered);$guard->shouldReceive('logout')->once();Auth::shouldReceive('guard')->with('admin')->andReturn($guard);
    $request=Request::create('http://admin.bizismart.co.tz/platform-management');$request->setLaravelSession(app('session')->driver('array'));$request->session()->put('admin_last_activity',now()->timestamp-$idle);
    $response=(new AdminSessionSecurity)->handle($request,fn()=>throw new LogicException('Expired access must not reach the panel.'));
    expect($response->getStatusCode())->toBe(302)->and($response->getTargetUrl())->toContain('/login')->and($request->session()->get('admin_last_activity'))->toBeNull()->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([[true,false,1800],[true,true,0],[false,false,0]]);
