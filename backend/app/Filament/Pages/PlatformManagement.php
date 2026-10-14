<?php
namespace App\Filament\Pages;
use App\Filament\Services\PlatformService;
use App\Models\Plan;
use Filament\Pages\Page;
use Illuminate\Support\Str;
class PlatformManagement extends Page {
    protected string $view='filament.pages.platform-management';
    protected static ?string $title='Clients, plans & subscriptions';
    public string $search='';public string $action='onboard';public array $form=[];public string $operation='';public ?array $result=null;
    public string $staffClient='';public string $staffSearch='';
    public function mount():void {$this->newAction('onboard');}
    public function newAction(string $action):void {
        if(!in_array($action,['onboard','plan','subscription','features'],true))abort(422);
        $this->action=$action;$this->operation=(string)Str::uuid();$this->result=null;$this->resetValidation();
        $features=array_fill_keys(config('platform.features'),false);
        $this->form=match($action){'plan'=>['code'=>'','name'=>'','price_monthly'=>0,'limits'=>['tills'=>1,'users'=>2,'products'=>200],'features'=>$features], 'onboard'=>['name'=>'','subdomain'=>'','owner_phone'=>'','plan_code'=>'','starts_on'=>now('Africa/Dar_es_Salaam')->toDateString(),'ends_on'=>''], 'subscription'=>['business_id'=>'','plan_code'=>'','expected_revision'=>0,'starts_on'=>'','ends_on'=>'','status'=>'active'], 'features'=>['business_id'=>'','features'=>$features]};
    }
    public function editPlan(string $code):void {$row=Plan::where('code',$code)->firstOrFail();$this->newAction('plan');$this->form=['code'=>$row->code,'name'=>$row->name,'price_monthly'=>$row->price_monthly,'limits'=>$row->limits,'features'=>array_replace(array_fill_keys(config('platform.features'),false),$row->features)];}
    public function editClient(string $public,string $action):void {$client=collect(app(PlatformService::class)->clients('',$public))->firstWhere('id',$public);if(!$client)abort(404);$this->newAction($action);$this->form['business_id']=$public;if($action==='subscription'){$s=$client['subscription'];$this->form=array_replace($this->form,['plan_code'=>$client['plan_code'],'expected_revision'=>$s['revision']??0,'starts_on'=>$s['starts_on']??now('Africa/Dar_es_Salaam')->toDateString(),'ends_on'=>$s['ends_on']??'','status'=>$s['status']??'active']);}else $this->form['features']=array_replace(array_fill_keys(config('platform.features'),false),$client['features']);}
    public function addPayment():void {$this->form['payment']=['amount'=>1,'method'=>'bank','reference'=>'','paid_on'=>now('Africa/Dar_es_Salaam')->toDateString()];}
    public function seedBaseline():void {
        $admin=\Illuminate\Support\Facades\Auth::guard('admin')->user();
        foreach(['free'=>['Free',0,1,2,200],'basic'=>['Basic',70000,2,5,null],'professional'=>['Professional',150000,5,15,null],'enterprise'=>['Enterprise',280000,null,null,null]] as $code=>$p){
            if(Plan::where('code',$code)->exists())continue;
            app(PlatformService::class)->execute($admin,'plan',['code'=>$code,'name'=>$p[0],'price_monthly'=>$p[1],'limits'=>['tills'=>$p[2],'users'=>$p[3],'products'=>$p[4]],'features'=>array_fill_keys(config('platform.features'),false)],(string)Str::uuid());
        }
        $this->newAction('plan');
    }
    public function removePayment():void {unset($this->form['payment']);}
    public function reissueInvite(string $public):void {$this->result=app(PlatformService::class)->execute(\Illuminate\Support\Facades\Auth::guard('admin')->user(),'invite',['business_id'=>$public],(string)Str::uuid());}
    public function save():void {
        $this->result=app(PlatformService::class)->execute(\Illuminate\Support\Facades\Auth::guard('admin')->user(),$this->action,$this->form,$this->operation);
        // Retain exact operation/result until the operator deliberately starts a new action.
    }
    protected function getViewData():array{return ['clients'=>app(PlatformService::class)->clients($this->search),'plans'=>Plan::orderBy('price_monthly')->get(),'featureKeys'=>config('platform.features'),'staff'=>$this->staffClient?app(PlatformService::class)->users($this->staffClient,$this->staffSearch):[]];}
}
