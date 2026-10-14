<?php
namespace App\Filament\Auth;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\DB;
class AdminAppAuthentication extends AppAuthentication {
    public function verifyCode(#[\SensitiveParameter] string $code,#[\SensitiveParameter] ?string $secret=null,bool $shouldPreventCodeReuse=false):bool {
        if(!$shouldPreventCodeReuse)return parent::verifyCode($code,$secret,false);
        $secret??=$this->getSecret(\Filament\Facades\Filament::auth()->user());
        return DB::transaction(function()use($secret,$code){$hash=hash_hmac('sha256',$secret,config('app.key'));DB::table('admin_totp_steps')->insertOrIgnore(['secret_hash'=>$hash,'last_step'=>0]);$row=DB::table('admin_totp_steps')->where('secret_hash',$hash)->lockForUpdate()->first();$step=$this->google2FA->verifyKeyNewer($secret,$code,(int)$row->last_step,$this->getCodeWindow());if($step===false)return false;DB::table('admin_totp_steps')->where('secret_hash',$hash)->update(['last_step'=>$step]);return true;},3);
    }
}
