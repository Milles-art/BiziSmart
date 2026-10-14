<?php
use App\Http\Middleware\ConfigureAdminSession;
use Illuminate\Http\Request;
uses(Tests\TestCase::class);
it('configures every admin request before session loading and restores defaults for tenant hosts',function(){
    config(['platform.admin_domain'=>'admin.bizismart.test','session.cookie'=>'tenant_default','session.domain'=>'.bizismart.test','session.encrypt'=>false,'session.lifetime'=>120]);
    $middleware=new ConfigureAdminSession;
    foreach(['/login','/livewire/update','/platform-management'] as $path){
        $middleware->handle(Request::create('http://admin.bizismart.test'.$path),function(){expect(config('session.cookie'))->toBe('bizismart_admin_session')->and(config('session.domain'))->toBeNull()->and(config('session.encrypt'))->toBeTrue()->and(config('session.lifetime'))->toBe(30);return response('ok');});
        expect(config('session.cookie'))->toBe('tenant_default')->and(config('session.domain'))->toBe('.bizismart.test');
    }
    $middleware->handle(Request::create('http://alpha.bizismart.test/api/v1/me'),function(){expect(config('session.cookie'))->toBe('tenant_default')->and(config('session.encrypt'))->toBeFalse();return response('ok');});
});
