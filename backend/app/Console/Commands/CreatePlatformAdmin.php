<?php
namespace App\Console\Commands;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
class CreatePlatformAdmin extends Command {
    protected $signature='bizismart:admin-create';protected $description='Create a separate platform administrator with email and password.';
    public function handle():int {
        $data=['name'=>$this->ask('Name'),'email'=>$this->ask('Email'),'password'=>$this->secret('Password (at least 12 characters)')];
        $v=Validator::make($data,['name'=>'required|string|max:120','email'=>'required|email|unique:admin_users,email','password'=>['required','string',new \App\Rules\AdminPasswordLength]]);if($v->fails()){$this->error('Invalid name/email/password or existing administrator.');return self::FAILURE;}
        $data['email']=strtolower(trim($data['email']));AdminUser::create($data);$this->info('Administrator created. Sign in to the admin domain with your email and password.');return self::SUCCESS;
    }
}
