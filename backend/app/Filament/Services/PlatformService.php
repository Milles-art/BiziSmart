<?php
namespace App\Filament\Services;
use App\Models\{AdminUser,Business,Branch,Plan,PlatformOperation,Subscription,SubscriptionPayment};
use App\Services\{OwnerSetupService,StaffRoleService,SupervisorApprovalService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;
class PlatformService {
    public function __construct(private TenantContext $context){}
    public function execute(AdminUser $admin,string $action,array $input,string $operation):array {
        Validator::make(['operation'=>$operation],['operation'=>'required|uuid'])->validate();
        $fingerprint=SupervisorApprovalService::snapshotHash(['action'=>$action,'input'=>$input]);
        return DB::transaction(function()use($admin,$action,$input,$operation,$fingerprint){
            $fresh=AdminUser::whereKey($admin->id)->lockForUpdate()->firstOrFail();if(!$fresh->is_active||!$fresh->app_authentication_secret)throw new \Illuminate\Auth\Access\AuthorizationException;
            $existing=PlatformOperation::where('operation_id',strtolower($operation))->lockForUpdate()->first();
            if($existing){if($existing->admin_id!==$admin->id||!hash_equals($existing->fingerprint,$fingerprint))throw ValidationException::withMessages(['operation'=>'This operation reference was already used with different details.']);return [...$existing->result,'replayed'=>true];}
            $result=match($action){'plan'=>$this->plan($input),'onboard'=>$this->onboard($input),'invite'=>$this->invite($input),'subscription'=>$this->subscription($admin,$input),'features'=>$this->features($input),default=>throw ValidationException::withMessages(['action'=>'Unsupported platform action.'])};
            PlatformOperation::create(['operation_id'=>strtolower($operation),'admin_id'=>$admin->id,'action'=>$action,'fingerprint'=>$fingerprint,'input'=>$input,'result'=>$result]);return [...$result,'replayed'=>false];
        },3);
    }
    private function plan(array $input):array {
        $v=Validator::make($input,['code'=>'required|string|max:32|regex:/^[a-z0-9-]+$/','name'=>'required|string|max:120','price_monthly'=>'required|integer|min:0|max:9007199254740991','limits'=>'required|array:tills,users,products','limits.tills'=>'nullable|integer|min:1|max:1000000','limits.users'=>'nullable|integer|min:1|max:1000000','limits.products'=>'nullable|integer|min:1|max:10000000','features'=>'required|array:'.implode(',',config('platform.features'))]);
        foreach(config('platform.features') as $key)$v->addRules(['features.'.$key=>'required|boolean']);$data=$v->validate();
        if($data['features']['tra'])throw ValidationException::withMessages(['features.tra'=>'TRA remains disabled until the final verified integration.']);
        foreach($data['limits'] as $key=>$value)$data['limits'][$key]=$value===null||$value===''?null:(int)$value;
        $data['price_monthly']=(int)$data['price_monthly'];
        $row=Plan::where('code',$data['code'])->lockForUpdate()->first();
        if($row){foreach(Business::where('plan_id',$row->id)->lockForUpdate()->get() as $shop)$this->context->run($shop,fn()=>$this->capacity($data['limits']));$row->fill($data)->save();}else $row=Plan::create($data);
        return ['plan_code'=>$row->code];
    }
    private function capacity(array $limits):void {
        $queries=['users'=>\App\Models\User::where('is_active',true),'products'=>\App\Models\Product::where('is_active',true),'tills'=>\App\Models\TillSession::where('status','open')];
        foreach($queries as $key=>$query){if(!isset($limits[$key]))continue;$limit=(int)$limits[$key];$count=$query->limit($limit+1)->lockForUpdate()->get(['id'])->count();if($count>$limit)throw ValidationException::withMessages(['limits.'.$key=>'This limit is below an existing client’s active usage.']);}
    }
    private function onboard(array $input):array {
        $data=Validator::make($input,['name'=>'required|string|max:120','subdomain'=>['required','string','max:63','regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',Rule::notIn(config('tenancy.reserved_subdomains')),Rule::unique('businesses','subdomain')],'owner_phone'=>'required|string','plan_code'=>'required|string','starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after:starts_on'])->validate();
        $phone=\App\Support\StaffPhone::normalize($data['owner_phone']);$plan=Plan::where('code',$data['plan_code'])->lockForUpdate()->firstOrFail();
        foreach(config('platform.features') as $key)if(!array_key_exists($key,$plan->features??[]))throw ValidationException::withMessages(['plan_code'=>'Assign every plan feature explicitly before onboarding.']);
        $shop=Business::create(['name'=>$data['name'],'subdomain'=>$data['subdomain'],'owner_phone'=>$phone,'plan_id'=>$plan->id]);
        $token=$this->context->run($shop,function()use($data,$plan){app(StaffRoleService::class)->provision();Branch::create(['name'=>'Main']);Subscription::create(['plan_id'=>$plan->id,'starts_at'=>$this->date($data['starts_on']),'ends_at'=>$this->date($data['ends_on']),'status'=>'active']);return app(OwnerSetupService::class)->invite();});
        // The owner creates a chosen PIN and mandatory TOTP via the existing one-use flow.
        return ['business_id'=>$shop->publicId(),'setup_url'=>'https://'.$shop->subdomain.'.'.config('tenancy.domain').'/#setup='.$token,'expires_in_minutes'=>90];
    }
    private function subscription(AdminUser $admin,array $input):array {
        $data=Validator::make($input,['business_id'=>'required|string','plan_code'=>'required|string','expected_revision'=>'required|integer|min:0','starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after:starts_on','status'=>['required',Rule::in(['active','suspended','expired'])],'payment'=>'nullable|array:amount,method,reference,paid_on','payment.amount'=>'required_with:payment|integer|min:1|max:9007199254740991','payment.method'=>['required_with:payment',Rule::in(['cash','bank','mobile'])],'payment.reference'=>'required_with:payment|string|max:160','payment.paid_on'=>'required_with:payment|date_format:Y-m-d|before_or_equal:today'])->validate();
        $shop=$this->shop($data['business_id']);$plan=Plan::where('code',$data['plan_code'])->lockForUpdate()->firstOrFail();
        return $this->context->run($shop,function()use($shop,$plan,$data,$admin){$this->capacity($plan->limits);$row=Subscription::lockForUpdate()->first();if(($row?->revision??0)!==(int)$data['expected_revision'])throw ValidationException::withMessages(['expected_revision'=>'Refresh this subscription before saving.']);$row??=new Subscription;$row->fill(['plan_id'=>$plan->id,'starts_at'=>$this->date($data['starts_on']),'ends_at'=>$this->date($data['ends_on']),'status'=>$data['status'],'revision'=>($row->revision??0)+1])->save();$shop->plan_id=$plan->id;$shop->save();if(isset($data['payment'])){$p=$data['payment'];$hash=hash('sha256',trim($p['reference']));if(SubscriptionPayment::where('reference_hash',$hash)->exists())throw ValidationException::withMessages(['payment.reference'=>'This payment reference has already been recorded.']);SubscriptionPayment::create(['subscription_id'=>$row->id,'amount'=>$p['amount'],'method'=>$p['method'],'reference'=>trim($p['reference']),'reference_hash'=>$hash,'paid_at'=>$this->date($p['paid_on']),'recorded_by'=>$admin->id]);}return ['subscription_id'=>$row->publicId(),'revision'=>$row->revision];});
    }
    private function features(array $input):array {
        $v=Validator::make($input,['business_id'=>'required|string','features'=>'required|array:'.implode(',',config('platform.features'))]);foreach(config('platform.features') as $key)$v->addRules(['features.'.$key=>'required|boolean']);$data=$v->validate();if($data['features']['tra'])throw ValidationException::withMessages(['features.tra'=>'TRA remains disabled.']);$shop=$this->shop($data['business_id']);$settings=$shop->settings??[];$settings['feature_overrides']=$data['features'];$shop->settings=$settings;$shop->save();return ['business_id'=>$shop->publicId(),'features'=>$data['features']];
    }
    private function shop(string $public):Business{return (new Business)->resolveRouteBindingQuery(Business::query(),$public)->lockForUpdate()->firstOrFail();}
    private function invite(array $input):array {$data=Validator::make($input,['business_id'=>'required|string'])->validate();$shop=$this->shop($data['business_id']);$token=$this->context->run($shop,fn()=>app(OwnerSetupService::class)->invite());return ['business_id'=>$shop->publicId(),'setup_url'=>'https://'.$shop->subdomain.'.'.config('tenancy.domain').'/#setup='.$token,'expires_in_minutes'=>90];}
    public function users(string $public,string $search):array {
        $shop=(new Business)->resolveRouteBindingQuery(Business::where('is_active',true),$public)->firstOrFail();
        return $this->context->run($shop,function()use($search){$result=[];$needle=mb_strtolower(mb_substr($search,0,120));foreach(\App\Models\User::withTrashed()->orderBy('id')->lazyById(100) as $user){if($needle!==''&&!str_contains(mb_strtolower($user->name),$needle)&&!str_contains($user->phone,$needle))continue;$result[]=['id'=>$user->publicId(),'name'=>$user->name,'phone'=>$user->phone,'active'=>$user->is_active&&!$user->trashed(),'role'=>$user->getRoleNames()->first(),'last_login_at'=>$user->last_login_at?->toISOString()];if(count($result)>=50)break;}return $result;});
    }
    private function date(string $date):CarbonImmutable{return CarbonImmutable::createFromFormat('!Y-m-d',$date,'Africa/Dar_es_Salaam')->utc();}
    public function clients(string $search='',?string $public=null):array {
        $query=Business::query();if($public!==null)$query=(new Business)->resolveRouteBindingQuery($query,$public);else $query->where('name','like','%'.addcslashes($search,'%_').'%');
        return $query->where('is_active',true)->orderBy('name')->limit(50)->get()->map(function($shop){return $this->context->run($shop,function()use($shop){$s=Subscription::first();return ['id'=>$shop->publicId(),'name'=>$shop->name,'subdomain'=>$shop->subdomain,'plan_code'=>$shop->plan->code,'active'=>$shop->is_active,'subscription'=>$s?['id'=>$s->publicId(),'status'=>$s->status,'revision'=>$s->revision,'starts_on'=>$s->starts_at->setTimezone('Africa/Dar_es_Salaam')->toDateString(),'ends_on'=>$s->ends_at->setTimezone('Africa/Dar_es_Salaam')->toDateString()]:null,'owner_configured'=>\App\Models\User::whereHas('roles',fn($q)=>$q->where('name','owner'))->where('is_active',true)->exists(),'features'=>array_replace($shop->plan->features??[],$shop->settings['feature_overrides']??[])];});})->all();
    }
}
