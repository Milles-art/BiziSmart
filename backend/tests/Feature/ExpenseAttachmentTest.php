<?php
use App\Models\{Activity,Branch,Business,Device,Plan,SupervisorAttachment,User,TillSession,TillCashEntry,TillOperation};
use App\Services\{StaffRoleService,SupervisorAttachmentService,TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->travelTo(now()->setDate(2026,10,13)->setTime(9,0));
    $plan = Plan::create(['code' => 'attachment', 'name' => 'Attachment', 'price_monthly' => 0, 'limits' => ['tills' => 2], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'approval-device-01', 'name' => 'Checkout device', 'status' => 'approved']);
            foreach (['owner' => '0700000001', 'manager' => '0700000002', 'cashier' => '0700000003', 'stockkeeper' => '0700000004'] as $role => $phone) {
                $user = User::create(['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') {
                    $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save();
                }
                $token = $user->createToken('approval-test', ['*'], now()->addDays(30));
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$label.'Device'} = $device;
        });
    }
    $this->withToken($this->alphaOwnerToken);
    config(['product_images.disk'=>'attachment-test','filesystems.disks.attachment-test'=>['driver'=>'local'],'product_images.max_bytes'=>2097152,'product_images.max_dimension'=>4096,'product_images.max_pixels'=>16777216]);
    \Illuminate\Support\Facades\Storage::fake('attachment-test');
    $this->expense=$this->context->run($this->alpha,fn()=>\App\Models\Expense::create(['actor_id'=>$this->alphaOwner->id,'amount'=>1000,'category'=>'Rent','description'=>'Private receipt','incurred_on'=>'2026-10-12']));
});
afterEach(function(){foreach($this->photoFiles??[] as $path)if(is_file($path))unlink($path);$this->context->clear();$this->travelBack();});

function expensePhotoUrl($t,string $suffix='',string $shop='alpha'):string{return 'http://'.$shop.'.bizismart.test/api/v1/expenses/'.$t->expense->publicId().'/attachments'.$suffix;}
function expensePhotoFile($test,bool $different=false){
 $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS9sAAAAASUVORK5CYII=');
 if($different){$data='Comment'.chr(0).'Different receipt';$chunk='tEXt'.$data;$bytes=substr($bytes,0,-12).pack('N',strlen($data)).$chunk.pack('N',crc32($chunk)).substr($bytes,-12);}
 $path=tempnam(sys_get_temp_dir(),'bizi-expense-photo-');file_put_contents($path,$bytes);$test->photoFiles[]=$path;
 return new \Illuminate\Http\UploadedFile($path,'receipt.png','image/png',null,true);
}
function expensePhotoUpload($t,string $key,$file=null){return $t->post(expensePhotoUrl($t),['operation_id'=>$key,'image'=>$file??expensePhotoFile($t)],['Accept'=>'application/json']);}

it('stores one private photo and returns an immutable successful upload replay',function(){
 $key=(string)Str::uuid();$file=expensePhotoFile($this);$id=expensePhotoUpload($this,$key,$file)->assertCreated()->json('data.attachment.id');
 expensePhotoUpload($this,$key,$file)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.attachment.id',$id);
 $this->getJson(expensePhotoUrl($this))->assertOk()->assertJsonCount(1,'data.attachments');
 $this->context->run($this->alpha,function(){expect(\App\Models\ExpenseAttachment::count())->toBe(1);$photo=\App\Models\ExpenseAttachment::first();expect($photo->status)->toBe('linked');expect($photo->getRawOriginal('path'))->not->toContain('businesses/');\Illuminate\Support\Facades\Storage::disk('attachment-test')->assertExists($photo->path);});
 $response=$this->get(expensePhotoUrl($this,'/'.$id))->assertOk()->assertHeader('X-Content-Type-Options','nosniff');expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
 expensePhotoUpload($this,$key,expensePhotoFile($this,true))->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
});
it('rejects spoofed bytes nonowners foreign tenants and another expenses attachment',function(){
 expensePhotoUpload($this,(string)Str::uuid(),\Illuminate\Http\UploadedFile::fake()->createWithContent('fake.png','<svg xmlns="http://www.w3.org/2000/svg"/>'))->assertUnprocessable();
 $key=(string)Str::uuid();$id=expensePhotoUpload($this,$key)->assertCreated()->json('data.attachment.id');
 $this->withToken($this->alphaManagerToken)->getJson(expensePhotoUrl($this))->assertForbidden();
 expensePhotoUpload($this,(string)Str::uuid())->assertForbidden();
 $this->withToken($this->betaOwnerToken)->getJson(expensePhotoUrl($this,'/'.$id,'beta'))->assertNotFound();
 $this->withToken($this->alphaOwnerToken);$other=$this->context->run($this->alpha,fn()=>\App\Models\Expense::create(['actor_id'=>$this->alphaOwner->id,'amount'=>2,'category'=>'Other','description'=>'Other receipt','incurred_on'=>'2026-10-12']));
 $this->getJson(str_replace($this->expense->publicId(),$other->publicId(),expensePhotoUrl($this,'/'.$id)))->assertNotFound();
 $this->context->run($this->alpha,fn()=>expect(\App\Models\ExpenseAttachment::count())->toBe(1));
});
it('cleans stale removed photos without deleting linked photos or resurrecting a removed replay',function(){
 $key=(string)Str::uuid();$file=expensePhotoFile($this);$id=expensePhotoUpload($this,$key,$file)->assertCreated()->json('data.attachment.id');
 $other=expensePhotoUpload($this,(string)Str::uuid())->assertCreated()->json('data.attachment.id');
 $this->deleteJson(expensePhotoUrl($this,'/'.$id))->assertOk();
 expensePhotoUpload($this,$key,$file)->assertConflict()->assertJsonPath('error.code','ATTACHMENT_REMOVED');
 $this->travel(61)->minutes();
 $this->context->run($this->alpha,function()use($id,$other){$removed=(new \App\Models\ExpenseAttachment)->resolveRouteBinding($id);$linked=(new \App\Models\ExpenseAttachment)->resolveRouteBinding($other);expect(app(\App\Services\ExpenseAttachmentService::class)->cleanup())->toBe(1);expect($removed->fresh()->status)->toBe('deleted');\Illuminate\Support\Facades\Storage::disk('attachment-test')->assertMissing($removed->path)->assertExists($linked->path);expect(app(\App\Services\ExpenseAttachmentService::class)->cleanup())->toBe(0);});
 $this->getJson(expensePhotoUrl($this,'/'.$id))->assertNotFound();
});
it('respects a fresh deleting claim and recovers a stale claim without allowing upload resurrection',function(){
 $key=(string)Str::uuid();$file=expensePhotoFile($this);$id=expensePhotoUpload($this,$key,$file)->assertCreated()->json('data.attachment.id');
 $this->context->run($this->alpha,function()use($id){$photo=(new \App\Models\ExpenseAttachment)->resolveRouteBinding($id);$photo->status='deleting';$photo->save();expect(app(\App\Services\ExpenseAttachmentService::class)->cleanup())->toBe(0);\Illuminate\Support\Facades\Storage::disk('attachment-test')->assertExists($photo->path);});
 expensePhotoUpload($this,$key,$file)->assertConflict()->assertJsonPath('error.code','ATTACHMENT_REMOVED');
 $this->travel(61)->minutes();
 $this->context->run($this->alpha,function()use($id){expect(app(\App\Services\ExpenseAttachmentService::class)->cleanup())->toBe(1);$photo=(new \App\Models\ExpenseAttachment)->resolveRouteBinding($id);expect($photo->status)->toBe('deleted');\Illuminate\Support\Facades\Storage::disk('attachment-test')->assertMissing($photo->path);});
});

