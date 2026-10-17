<?php
namespace App\Models;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
class AdminUser extends Authenticatable implements FilamentUser {
    use \Illuminate\Notifications\Notifiable;
    protected $fillable=['name','email','password','is_active'];
    protected $hidden=['password','remember_token','app_authentication_secret'];
    protected function casts():array{return ['password'=>'hashed','is_active'=>'boolean','app_authentication_secret'=>'encrypted'];}
    public function canAccessPanel(Panel $panel):bool{return $panel->getId()==='admin'&&$this->is_active;}
}
