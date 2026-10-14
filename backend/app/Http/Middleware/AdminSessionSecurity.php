<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AdminSessionSecurity {
    public function handle(Request $request,Closure $next){
        if(config('platform.allowed_ips')&&!in_array($request->ip(),config('platform.allowed_ips'),true))abort(403);
        $guard=Auth::guard('admin');
        if($guard->check()){
            $last=$request->session()->get('admin_last_activity');
            if(!$guard->user()->is_active||$guard->viaRemember()||($last&&now()->timestamp-(int)$last>=1800)){$guard->logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('filament.admin.auth.login')->header('Cache-Control','no-store, private');}
            $request->session()->put('admin_last_activity',now()->timestamp);
        }
        $response=$next($request);$response->headers->set('Cache-Control','no-store, private');return $response;
    }
}
