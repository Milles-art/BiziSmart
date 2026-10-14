<?php
namespace App\Http\Middleware;
use Closure;
class ConfigureAdminSession {
    public function handle($request,Closure $next){
        if($request->getHost()!==config('platform.admin_domain'))return $next($request);
        $original=[];foreach(['cookie','domain','lifetime','encrypt','driver','secure'] as $key)$original['session.'.$key]=config('session.'.$key);
        config(['session.cookie'=>'bizismart_admin_session','session.domain'=>null,'session.lifetime'=>30,'session.encrypt'=>true]);
        if(!app()->environment('testing')&&config('session.driver')==='array')config(['session.driver'=>'file']);
        if(app()->environment('production'))config(['session.secure'=>true]);
        try{return $next($request);}finally{config($original);}
    }
}
