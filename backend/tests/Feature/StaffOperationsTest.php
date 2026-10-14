<?php

use App\Models\{AttendanceSession,AttendanceCorrection,StaffOperation,NotificationPreference,Business,Branch,Device,Plan,User};
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain'=>'bizismart.test']);
    $this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'attendance-tests','name'=>'Attendance','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach (['alpha','beta'] as $label) {
        $business=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);
        $this->{$label}=$business;
        $this->context->run($business,function () use ($label) {
            app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);
            $device=Device::create(['device_uid'=>'attendance-tests','name'=>'Attendance tests','status'=>'approved']);
            foreach (['owner','cashier','manager','stockkeeper'] as $i=>$role) {
                $user=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);
                app(StaffRoleService::class)->assign($user,$role);
                if ($role==='owner') {$user->totp_secret='JBSWY3DPEHPK3PXP';$user->totp_confirmed_at=now();$user->save();}
                $token=$user->createToken('attendance-tests',['*'],now()->addDays(2));$token->accessToken->device_id=$device->id;$token->accessToken->save();
                $this->{$label.ucfirst($role)}=$user;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
        });
    }
    $this->withToken($this->alphaCashierToken);
});
afterEach(function () {$this->context->clear();$this->travelBack();});
function attendanceUrl(string $action=''):string {return 'http://alpha.bizismart.test/api/v1/staff-operations'.($action?'/'.$action:'');}
function attendanceWrite($test,string $action,array $body=[],?string $key=null) {return $test->withHeader('Idempotency-Key',$key??(string)Str::uuid())->postJson(attendanceUrl($action),$body);}

it('records explicit server stamps once and replays clock out after its session closes',function () {
    $key=(string)Str::uuid();$first=attendanceWrite($this,'clock_in',[],$key)->assertOk()->json('data.attendance');
    attendanceWrite($this,'clock_in',[],$key)->assertOk()->assertJsonPath('data.attendance.id',$first['id'])->assertJsonPath('data.replayed',true);
    attendanceWrite($this,'clock_in')->assertConflict()->assertJsonPath('error.code','ATTENDANCE_ALREADY_OPEN');
    $this->travel(10)->minutes();$outKey=(string)Str::uuid();$closed=attendanceWrite($this,'clock_out',[],$outKey)->assertOk()->json('data.attendance');
    expect($closed['original_started_at'])->toBe($first['original_started_at']);expect($closed['original_ended_at'])->not->toBeNull();
    attendanceWrite($this,'clock_out',[],$outKey)->assertOk()->assertJsonPath('data.attendance.original_ended_at',$closed['original_ended_at'])->assertJsonPath('data.replayed',true);
    attendanceWrite($this,'clock_out')->assertConflict()->assertJsonPath('error.code','ATTENDANCE_NOT_OPEN');
    $this->context->run($this->alpha,function () {expect(AttendanceSession::count())->toBe(1);expect(StaffOperation::count())->toBe(2);});
});
it('rejects supplied identities or stamps and never lets a different actor reuse an operation',function () {
    attendanceWrite($this,'clock_in',['started_at'=>'2000-01-01','user_id'=>$this->alphaOwner->publicId()])->assertUnprocessable();
    $key=(string)Str::uuid();attendanceWrite($this,'clock_in',[],$key)->assertOk();
    $this->withToken($this->alphaManagerToken);attendanceWrite($this,'clock_in',[],$key)->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->getJson(attendanceUrl())->assertOk()->assertJsonCount(0,'data.attendance');
    attendanceWrite($this,'clock_out')->assertConflict();
});
it('retains original stamps in reasoned owner corrections and rejects stale or foreign records',function () {
    $record=attendanceWrite($this,'clock_in')->assertOk()->json('data.attendance');
    $this->travel(5)->minutes();
    $body=['attendance_id'=>$record['id'],'expected_revision'=>0,'corrected_started_at'=>now('Africa/Dar_es_Salaam')->subMinutes(6)->format('Y-m-d\TH:i'),'corrected_ended_at'=>now('Africa/Dar_es_Salaam')->subMinute()->format('Y-m-d\TH:i'),'reason'=>'Correct a missed clock-out'];
    $this->withToken($this->alphaManagerToken);attendanceWrite($this,'correction',$body)->assertForbidden();
    $this->withToken($this->betaOwnerToken)->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(str_replace('alpha.','beta.',attendanceUrl('correction')),$body)->assertNotFound();
    $this->withToken($this->alphaOwnerToken);$key=(string)Str::uuid();attendanceWrite($this,'correction',$body,$key)->assertOk()->assertJsonPath('data.attendance.original_started_at',$record['original_started_at'])->assertJsonPath('data.attendance.original_ended_at',null)->assertJsonPath('data.attendance.is_open',false);
    attendanceWrite($this,'correction',$body,$key)->assertOk()->assertJsonPath('data.replayed',true);
    attendanceWrite($this,'correction',$body)->assertConflict()->assertJsonPath('error.code','ATTENDANCE_CHANGED');
    $this->context->run($this->alpha,function () use ($record) {expect(AttendanceCorrection::count())->toBe(1);expect(AttendanceCorrection::first()->original_snapshot['original_started_at'])->toBe($record['original_started_at']);});
    $this->withToken($this->alphaCashierToken);attendanceWrite($this,'clock_in')->assertOk();
});
it('keeps notification preferences private with revision checks and truthful external delivery status',function () {
    $this->getJson(attendanceUrl())->assertOk()->assertJsonPath('data.preferences.whatsapp',false)->assertJsonPath('data.channels.sms.ready',false);
    $body=['expected_revision'=>0,'in_app'=>false,'whatsapp'=>true,'sms'=>true];$key=(string)Str::uuid();
    attendanceWrite($this,'preferences',$body,$key)->assertOk()->assertJsonPath('data.external_delivery_executed',false);
    attendanceWrite($this,'preferences',$body,$key)->assertOk()->assertJsonPath('data.replayed',true);
    attendanceWrite($this,'preferences',$body)->assertConflict()->assertJsonPath('error.code','PREFERENCES_CHANGED');
    $this->withToken($this->alphaStockkeeperToken)->getJson(attendanceUrl())->assertOk()->assertJsonPath('data.preferences.in_app',true)->assertJsonPath('data.preferences.revision',0);
    $this->context->run($this->alpha,fn()=>expect(NotificationPreference::count())->toBe(1));
});
