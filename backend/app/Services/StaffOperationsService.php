<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,User,AttendanceSession,AttendanceCorrection,NotificationPreference,StaffNotification,StaffOperation,TillSession};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Carbon\CarbonImmutable;

class StaffOperationsService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors,private StockService $stock){}
    private function actor(User $original):User {$actor=$this->actors->lockSession($original);Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();return $actor;}
    private function owner(User $actor):void {if(!$actor->hasRole('owner')||!$actor->can('manage_users'))throw new AuthorizationException;}
    public function overview(User $original):array {
        return DB::transaction(function()use($original){$actor=$this->actor($original);
            $prefs=NotificationPreference::where('user_id',$actor->id)->first();$preferences=$this->preferences($prefs);
            if($preferences['in_app'])$this->notices($actor);
            $history=AttendanceSession::query();if(!$actor->hasRole('owner'))$history->where('user_id',$actor->id);
            $current=AttendanceSession::where('active_user_id',$actor->id)->first();
            return ['current'=>$current?$this->session($current):null,
                'attendance'=>$history->latest('id')->limit(100)->get()->map(fn($row)=>$this->session($row))->all(),
                'preferences'=>$preferences,'channels'=>['in_app'=>['ready'=>true],...app(MessageTransport::class)->capabilities()],
                'notifications'=>$this->visible($actor)->latest('id')->limit(50)->get()->map(fn($notice)=>['id'=>$notice->publicId(),'type'=>$notice->type,'payload'=>$notice->payload,'read_at'=>$notice->read_at?->toISOString(),'created_at'=>$notice->created_at->toISOString()])->all()];
        },3);
    }
    public function commit(User $original,string $action,array $input,string $key):array {
        $peek=$action==='correction'?(new AttendanceSession)->resolveRouteBinding($input['attendance_id']):null;
        return DB::transaction(function()use($original,$action,$input,$key,$peek){
            if($peek)User::whereIn('id',array_unique([$original->id,$peek->user_id]))->orderBy('id')->lockForUpdate()->get();
            $actor=$this->actor($original);$key=strtolower($key);$hash=SupervisorApprovalService::snapshotHash(['action'=>$action,'input'=>$input]);
            if($action==='correction')$this->owner($actor);
            $existing=StaffOperation::where('operation_id',$key)->lockForUpdate()->first();
            if($existing){if((int)$existing->actor_id!==(int)$actor->id||(int)$existing->device_id!==(int)$actor->currentAccessToken()->device_id||!hash_equals($existing->request_fingerprint,$hash))throw new ApiException('OPERATION_ID_REUSED','This staff operation reference has different details.',409);return [...$existing->result,'replayed'=>true];}
            if($action==='clock_in'){
                if(AttendanceSession::where('active_user_id',$actor->id)->lockForUpdate()->exists())throw new ApiException('ATTENDANCE_ALREADY_OPEN','Clock out before starting another attendance session.',409);
                $session=AttendanceSession::create(['user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'active_user_id'=>$actor->id,'started_at'=>now(),'revision'=>0]);$result=['attendance'=>$this->session($session)];
            }elseif($action==='clock_out'){
                $session=AttendanceSession::where('active_user_id',$actor->id)->lockForUpdate()->first();if(!$session)throw new ApiException('ATTENDANCE_NOT_OPEN','You have no open attendance session.',409);
                $session->ended_at=now();$session->active_user_id=null;$session->revision++;$session->save();$result=['attendance'=>$this->session($session)];
            }elseif($action==='correction'){
                $session=$this->target(AttendanceSession::class,$input['attendance_id']);
                if($session->revision!==$input['expected_revision'])throw new ApiException('ATTENDANCE_CHANGED','This attendance record changed. Refresh it before correcting.',409);
                $start=$this->localTime($input['corrected_started_at']);$end=isset($input['corrected_ended_at'])?$this->localTime($input['corrected_ended_at']):null;
                if($start->isFuture()||$end?->isFuture()||($end&&$end->lessThan($start))||(!$session->active_user_id&&!$end))throw new ApiException('VALIDATION_ERROR','Corrected times must be ordered, must not be future times, and a closed session must keep an end time.',422);
                $snapshot=$this->session($session);
                AttendanceCorrection::create(['attendance_id'=>$session->id,'actor_id'=>$actor->id,'original_snapshot'=>$snapshot,'corrected_started_at'=>$start,'corrected_ended_at'=>$end,'reason'=>trim($input['reason'])]);
                // A recorded owner correction can close an open session without fabricating a staff clock-out stamp.
                if($end)$session->active_user_id=null;$session->revision++;$session->save();$result=['attendance'=>$this->session($session)];
            }elseif($action==='preferences'){
                $prefs=NotificationPreference::where('user_id',$actor->id)->lockForUpdate()->first();
                if(($prefs?->revision??0)!==$input['expected_revision'])throw new ApiException('PREFERENCES_CHANGED','Refresh your notification preferences before saving.',409);
                $prefs??=new NotificationPreference(['user_id'=>$actor->id]);$prefs->fill(['in_app'=>$input['in_app'],'whatsapp'=>$input['whatsapp'],'sms'=>$input['sms'],'revision'=>($prefs->revision??0)+1]);$prefs->save();$result=['preferences'=>$this->preferences($prefs),'external_delivery_executed'=>false];
            }elseif($action==='read_notification'){
                $notice=$this->visible($actor)->where('public_id',$this->target(StaffNotification::class,$input['notification_id'])->public_id)->lockForUpdate()->first();
                if(!$notice)throw new ApiException('NOT_FOUND','Notification not found.',404);if(!$notice->read_at){$notice->read_at=now();$notice->save();}$result=['notification_id'=>$notice->publicId(),'read_at'=>$notice->read_at->toISOString()];
            }else throw new ApiException('VALIDATION_ERROR','Unsupported staff operation.',422);
            StaffOperation::create(['actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$key,'request_fingerprint'=>$hash,'action'=>$action,'result'=>$result]);return [...$result,'replayed'=>false];
        },3);
    }
    private function target(string $class,string $id){$row=(new $class)->resolveRouteBindingQuery($class::query(),$id)->lockForUpdate()->first();if(!$row)throw new ApiException('NOT_FOUND','Staff record not found.',404);return $row;}
    private function localTime(string $value):CarbonImmutable {return CarbonImmutable::createFromFormat('!Y-m-d\TH:i',$value,'Africa/Dar_es_Salaam')->utc();}
    private function preferences(?NotificationPreference $p):array {return ['in_app'=>$p?->in_app??true,'whatsapp'=>$p?->whatsapp??false,'sms'=>$p?->sms??false,'revision'=>$p?->revision??0];}
    private function session(AttendanceSession $row):array {
        $correction=AttendanceCorrection::where('attendance_id',$row->id)->latest('id')->first();$staff=User::withTrashed()->findOrFail($row->user_id);
        return ['id'=>$row->publicId(),'user_id'=>$staff->publicId(),'staff_name'=>$staff->name,'is_open'=>$row->active_user_id!==null,'revision'=>$row->revision,
            'original_started_at'=>$row->started_at->toISOString(),'original_ended_at'=>$row->ended_at?->toISOString(),
            'started_at'=>($correction?->corrected_started_at??$row->started_at)->toISOString(),
            'ended_at'=>($correction?->corrected_ended_at??$row->ended_at)?->toISOString(),
            'correction'=>$correction?['id'=>$correction->publicId(),'reason'=>$correction->reason,'recorded_at'=>$correction->created_at->toISOString()]:null];
    }
    private function visible(User $actor){
        $permissions=[];foreach(['receive_stock','manage_till'] as $permission)if($actor->can($permission))$permissions[]=$permission;
        $query=StaffNotification::where('user_id',$actor->id)->whereIn('permission',$permissions);
        if($actor->hasRole('cashier'))$query->whereIn('till_session_id',TillSession::where('cashier_id',$actor->id)->where('branch_id',$actor->branch_id)->select('id'));
        if(!$actor->hasRole('owner'))$query->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$actor->branch_id));return $query;
    }
    private function notices(User $actor):void {
        if($actor->can('receive_stock'))foreach($this->stock->reorderAlerts()->limit(50)->get() as $product){$summary=$this->stock->summary($product);$payload=['product_id'=>$product->publicId(),'name'=>$product->name,'stock'=>$summary['stock'],'unit'=>$product->unit];$this->notice($actor,'stock_low','receive_stock',null,$payload);}
        if($actor->can('manage_till')){
            $query=TillSession::where('status','closed')->where('owner_review_required',true);
            if(!$actor->hasRole('owner'))$query->where('branch_id',$actor->branch_id);if($actor->hasRole('cashier'))$query->where('cashier_id',$actor->id);
            foreach($query->latest('id')->limit(50)->get() as $till)$this->notice($actor,'till_variance','manage_till',$till->branch_id,['till_id'=>$till->publicId(),'variance'=>$till->variance],$till->id);
        }
    }
    private function notice(User $actor,string $type,string $permission,?int $branch,array $payload,?int $tillId=null):void {
        $hash=SupervisorApprovalService::snapshotHash(['type'=>$type,'branch_id'=>$branch,'payload'=>$payload]);
        StaffNotification::firstOrCreate(['user_id'=>$actor->id,'event_key'=>$hash],['type'=>$type,'permission'=>$permission,'branch_id'=>$branch,'till_session_id'=>$tillId,'payload'=>$payload]);
    }
}
