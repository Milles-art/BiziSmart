<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\{Expense,ExpenseAttachment};
use App\Services\{ExpenseAttachmentService,ProductImageService};
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
class ExpenseAttachmentController {
 public function capabilities(ProductImageService $s){return ApiResponse::success($s->capabilities());}
 public function index(Request $r,Expense $expense,ExpenseAttachmentService $s){return ApiResponse::success(['attachments'=>$s->listing($r->user(),$expense)]);}
 public function store(Request $r,Expense $expense,ExpenseAttachmentService $s){$v=$r->validate(['operation_id'=>['required','uuid'],'image'=>['required','file','max:2048']]);$data=$s->upload($r->user(),$expense,$r->file('image'),$v['operation_id']);return ApiResponse::success($data,$data['replayed']?200:201);}
 public function show(Request $r,Expense $expense,ExpenseAttachment $attachment,ExpenseAttachmentService $s){return $s->stream($r->user(),$expense,$attachment);}
 public function remove(Request $r,Expense $expense,ExpenseAttachment $attachment,ExpenseAttachmentService $s){$s->remove($r->user(),$expense,$attachment);return ApiResponse::success(['removed'=>true]);}
}
