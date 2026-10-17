<?php

use App\Filament\Auth\{AdminLogin, AdminPasswordResetNotification, AdminProfile, RequestAdminPasswordReset, ResetAdminPassword};
use App\Filament\Services\PlatformService;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Hash, Notification, Password};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->admin = AdminUser::create(['name'=>'Platform owner', 'email'=>'admin@example.test', 'password'=>'original-password-123']);
});

it('signs administrators in with email and password without an authenticator including previously enrolled accounts', function (bool $enrolled) {
    if ($enrolled) {
        $this->admin->app_authentication_secret = 'JBSWY3DPEHPK3PXP';
        $this->admin->save();
    }
    expect(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeFalse();
    Livewire::test(AdminLogin::class)->fillForm(['email'=>$this->admin->email,'password'=>'original-password-123'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
    $this->assertAuthenticatedAs($this->admin, 'admin');
    expect($this->admin->fresh()->app_authentication_secret)->toBe($enrolled ? 'JBSWY3DPEHPK3PXP' : null);
})->with([false, true]);

it('rejects invalid passwords and inactive administrators', function (bool $active, string $password) {
    $this->admin->update(['is_active'=>$active]);
    Livewire::test(AdminLogin::class)->fillForm(['email'=>$this->admin->email,'password'=>$password])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest('admin');
})->with([[true,'incorrect-password-123'],[false,'original-password-123']]);

it('allows active password-only administrators to manage plans and rechecks revocation', function () {
    $input=['code'=>'password-only','name'=>'Password only','price_monthly'=>0,'limits'=>['tills'=>1,'users'=>1,'products'=>1], 'features'=>array_fill_keys(config('platform.features'), false)];
    expect(app(PlatformService::class)->execute($this->admin,'plan',$input,(string) Str::uuid())['plan_code'])->toBe('password-only');
    AdminUser::whereKey($this->admin->id)->update(['is_active'=>false]);
    expect(fn()=>app(PlatformService::class)->execute($this->admin,'plan',$input,(string)Str::uuid()))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('does not create or log reset tokens when real email delivery is unconfigured', function (string $mailer) {
    Notification::fake();
    config(['mail.default'=>$mailer]);
    Livewire::test(RequestAdminPasswordReset::class)->fillForm(['email'=>$this->admin->email])->call('request')->assertHasNoFormErrors();
    expect(DB::table('admin_password_reset_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['log','array','failover']);

it('issues a hashed expiring administrator-only reset token synchronously through configured email', function () {
    Notification::fake();
    config(['mail.default'=>'smtp','mail.from.address'=>'support@bizismart.com','mail.mailers.smtp.host'=>'smtp.provider.invalid','mail.mailers.smtp.username'=>'fixture-user','mail.mailers.smtp.password'=>'fixture-password']);
    Livewire::test(RequestAdminPasswordReset::class)->fillForm(['email'=>$this->admin->email])->call('request')->assertHasNoFormErrors();
    $token=null;
    Notification::assertSentTo($this->admin, AdminPasswordResetNotification::class, function ($notification) use (&$token) {
        $token=$notification->token;
        expect($notification)->not->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
        expect($notification->toMail($this->admin)->actionUrl)->toContain('/password-reset/reset?')->toContain('token=');
        return true;
    });
    expect(DB::table('admin_password_reset_tokens')->where('email',$this->admin->email)->value('token'))->not->toBe($token);
    expect(Password::broker('admins')->tokenExists($this->admin,$token))->toBeTrue();
    $this->travel(31)->minutes();
    expect(Password::broker('admins')->tokenExists($this->admin,$token))->toBeFalse();
    $this->travelBack();
});

it('does not issue reset credentials for inactive or missing administrators', function (bool $exists) {
    Notification::fake();
    config(['mail.default'=>'smtp','mail.from.address'=>'support@bizismart.com','mail.mailers.smtp.host'=>'smtp.provider.invalid','mail.mailers.smtp.username'=>'fixture-user','mail.mailers.smtp.password'=>'fixture-password']);
    $this->admin->update(['is_active'=>false]);
    Livewire::test(RequestAdminPasswordReset::class)->fillForm(['email'=>$exists?$this->admin->email:'unknown@example.test'])->call('request')->assertHasNoFormErrors();
    expect(DB::table('admin_password_reset_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
})->with([true,false]);

it('validates twelve-character reset passwords and consumes the link once', function () {
    $token=Password::broker('admins')->createToken($this->admin);
    Livewire::test(ResetAdminPassword::class,['email'=>$this->admin->email,'token'=>$token])
        ->fillForm(['password'=>'short123','passwordConfirmation'=>'short123'])->call('resetPassword')->assertHasFormErrors(['password'=>'min']);
    $page=Livewire::test(ResetAdminPassword::class,['email'=>$this->admin->email,'token'=>$token]);
    $page->fillForm(['password'=>'replacement-password-123','passwordConfirmation'=>'replacement-password-123'])
        ->call('resetPassword')->assertHasNoFormErrors()->assertRedirect();
    expect(Hash::check('replacement-password-123',$this->admin->fresh()->password))->toBeTrue()
        ->and(Password::broker('admins')->tokenExists($this->admin,$token))->toBeFalse();
    Livewire::test(ResetAdminPassword::class,['email'=>$this->admin->email,'token'=>$token])
        ->fillForm(['password'=>'attacker-replacement-123','passwordConfirmation'=>'attacker-replacement-123'])->call('resetPassword');
    expect(Hash::check('replacement-password-123',$this->admin->fresh()->password))->toBeTrue();
});

it('requires the current password and twelve characters for administrator profile changes', function () {
    $this->actingAs($this->admin,'admin');
    Livewire::test(AdminProfile::class)->fillForm(['password'=>'short123','passwordConfirmation'=>'short123','currentPassword'=>'original-password-123'])
        ->call('save')->assertHasFormErrors(['password'=>'min']);
    Livewire::test(AdminProfile::class)->fillForm(['password'=>'replacement-password-123','passwordConfirmation'=>'replacement-password-123','currentPassword'=>'incorrect-password-123'])
        ->call('save')->assertHasFormErrors(['currentPassword']);
    expect(Hash::check('original-password-123',$this->admin->fresh()->password))->toBeTrue();
});

it('rejects bcrypt suffix truncation and oversized multibyte or null passwords before authentication and reset', function (string $password) {
    $this->admin->password=str_repeat('a',72);$this->admin->save();
    Livewire::test(AdminLogin::class)->fillForm(['email'=>$this->admin->email,'password'=>$password])->call('authenticate')->assertHasFormErrors(['password']);
    $this->assertGuest('admin');
    $token=Password::broker('admins')->createToken($this->admin);
    Livewire::test(ResetAdminPassword::class,['email'=>$this->admin->email,'token'=>$token])
        ->fillForm(['password'=>$password,'passwordConfirmation'=>$password])->call('resetPassword')->assertHasFormErrors(['password']);
    expect(Hash::check(str_repeat('a',72),$this->admin->fresh()->password))->toBeTrue();
})->with([str_repeat('a',72).'different-suffix',str_repeat('é',37),"twelve-characters\0suffix"]);
