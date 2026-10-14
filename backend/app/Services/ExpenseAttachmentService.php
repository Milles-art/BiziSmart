<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Expense,ExpenseAttachment,User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ExpenseAttachmentService {
 public function __construct(private FinancialJournalService $journal,private ProductImageService $images){}
 private function authority(User $original):User{$a=$this->journal->lock($original);if(!$a->hasRole('owner'))throw new \Illuminate\Auth\Access\AuthorizationException;return $a;}
 public function view(ExpenseAttachment $a):array{$a->assertCurrentBusiness();return ['id'=>$a->publicId(),'mime'=>$a->mime,'bytes'=>$a->bytes,'width'=>$a->width,'height'=>$a->height,'created_at'=>$a->created_at->toISOString()];}
 public function listing(User $actor,Expense $expense):array{return DB::transaction(function()use($actor,$expense){$this->authority($actor);$expense->assertCurrentBusiness();return ExpenseAttachment::where('expense_id',$expense->id)->where('status','linked')->orderBy('id')->limit(20)->get()->map(fn($a)=>$this->view($a))->all();});}
 public function upload(User $original,Expense $expense,UploadedFile $file,string $key):array {
  $meta=$this->images->validatedImage($file);$hash=hash_file('sha256',$file->getRealPath());$fingerprint=SupervisorApprovalService::snapshotHash(['expense'=>$expense->publicId(),'hash'=>$hash]);$disk=$this->images->privateStorage();
  $record=DB::transaction(function()use($original,$expense,$key,$meta,$fingerprint){$actor=$this->authority($original);$expense->refreshForUpdate();$old=ExpenseAttachment::where('operation_id',strtolower($key))->lockForUpdate()->first();if($old){if($old->actor_id!==$actor->id||$old->device_id!==$actor->currentAccessToken()->device_id||$old->expense_id!==$expense->id||!hash_equals($old->fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This photo reference has different details.',409);if(in_array($old->status,['removed','deleting','deleted']))throw new ApiException('ATTACHMENT_REMOVED','This attachment was removed.',409);if($old->status!=='linked'){$old->status='pending';$old->touch();}return $old;}if(ExpenseAttachment::where('expense_id',$expense->id)->whereIn('status',['pending','linked'])->count()>=20)throw new ApiException('ATTACHMENT_LIMIT','An expense can have up to twenty photos.',422);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$meta['mime']];return ExpenseAttachment::create(['expense_id'=>$expense->id,'actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>strtolower($key),'fingerprint'=>$fingerprint,'status'=>'pending','path'=>'businesses/'.$expense->business_id.'/expenses/'.$expense->publicId().'/'.Str::ulid().'.'.$ext,...$meta]);},3);
  if($record->status==='linked')return ['attachment'=>$this->view($record),'replayed'=>true];
  $stream=fopen($file->getRealPath(),'rb');try{if(!$stream||!$disk->put($record->path,$stream,['visibility'=>'private','ContentType'=>$record->mime]))throw new \RuntimeException('Upload failed.');}catch(\Throwable){DB::transaction(function()use($record){$record->refreshForUpdate();if($record->status==='pending'){$record->status='cleanup';$record->save();}},3);throw new ApiException('IMAGE_STORAGE_UNAVAILABLE','The receipt photo could not be stored. Retry the same upload.',503);}finally{if(is_resource($stream))fclose($stream);}
  try{return DB::transaction(function()use($original,$record){$actor=$this->authority($original);$record->refreshForUpdate();if($record->status==='linked')return ['attachment'=>$this->view($record),'replayed'=>true];if(in_array($record->status,['removed','deleting','deleted']))throw new ApiException('ATTACHMENT_REMOVED','This attachment was removed.',409);$record->status='linked';$record->save();activity('expenses')->performedOn($record)->causedBy($actor)->event('expense_photo_added')->withProperties(['attachment_id'=>$record->publicId()])->log('Expense photo attached');return ['attachment'=>$this->view($record),'replayed'=>false];},3);}catch(\Throwable $e){DB::transaction(function()use($record){$record->refreshForUpdate();if($record->status==='pending'){$record->status='cleanup';$record->save();}},3);throw $e;}
 }
 public function remove(User $original,Expense $expense,ExpenseAttachment $attachment):void {DB::transaction(function()use($original,$expense,$attachment){$actor=$this->authority($original);$attachment->refreshForUpdate();abort_unless($attachment->expense_id===$expense->id,404);if($attachment->status==='linked'){$attachment->status='removed';$attachment->save();activity('expenses')->performedOn($attachment)->causedBy($actor)->event('expense_photo_removed')->withProperties(['attachment_id'=>$attachment->publicId()])->log('Expense photo removed');}},3);}
 public function stream(User $actor,Expense $expense,ExpenseAttachment $attachment){DB::transaction(function()use($actor,$expense,$attachment){$this->authority($actor);$attachment->assertCurrentBusiness();abort_unless($attachment->expense_id===$expense->id&&$attachment->status==='linked',404);});try{$stream=$this->images->privateStorage()->readStream($attachment->path);if(!is_resource($stream))throw new \RuntimeException('Unavailable private receipt.');}catch(\Throwable){throw new ApiException('IMAGE_STORAGE_UNAVAILABLE','The receipt photo could not be read.',503);}return response()->stream(function()use($stream){try{fpassthru($stream);}finally{fclose($stream);}},200,['Content-Type'=>$attachment->mime,'Content-Length'=>(string)$attachment->bytes,'Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline']);}
 public function cleanup():int
 {
  $disk=$this->images->privateStorage();$count=0;
  $candidates=ExpenseAttachment::whereIn('status',['pending','cleanup','removed','deleting'])->where('updated_at','<',now()->subHour())->orderBy('id')->limit(100)->pluck('id');
  foreach($candidates as $id){
   $record=DB::transaction(function()use($id){
    $row=ExpenseAttachment::whereKey($id)->lockForUpdate()->first();
    if(!$row||!in_array($row->status,['pending','cleanup','removed','deleting'])||$row->updated_at->gte(now()->subHour()))return null;
    $row->status='deleting';$row->save();return $row;
   },3);
   if(!$record)continue;
   try{
    if(!$disk->delete($record->path))continue;
    DB::transaction(function()use($record,&$count){$row=ExpenseAttachment::whereKey($record->id)->lockForUpdate()->first();if($row?->status==='deleting'){$row->status='deleted';$row->save();$count++;}},3);
   }catch(\Throwable){/* Retain the claimed cleanup record for a later retry. */}
  }
  return $count;
 }
}
