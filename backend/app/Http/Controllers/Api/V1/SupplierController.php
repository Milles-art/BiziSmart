<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Suppliers\SupplierActionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\SupplierService;
use App\Services\SupplierStatementService;
use App\Http\Requests\Suppliers\SupplierStatementRequest;
use App\Models\Supplier;
use Illuminate\Http\Request;
class SupplierController {
    public function __construct(private SupplierService $suppliers){}
    public function index(Request $r){return ApiResponse::success($this->suppliers->index($r->user()));}
    public function receiving(Request $r){return ApiResponse::success($this->suppliers->receiving($r->user()));}
    public function statement(SupplierStatementRequest $r, Supplier $supplier){return ApiResponse::success(app(SupplierStatementService::class)->build($r->user(),$supplier,$r->validated()));}
    public function statementCsv(SupplierStatementRequest $r, Supplier $supplier){
        $statement=app(SupplierStatementService::class)->build($r->user(),$supplier,$r->validated(),true);
        return response()->streamDownload(function()use($statement){
            $file=fopen('php://output','w');
            $cell=static function($value):string{$value=(string)$value;return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',$value)?"'".$value:$value;};
            $write=static function(array $row)use($file,$cell){fputcsv($file,array_map($cell,$row),',','"','');};
            $write(['Supplier',$statement['supplier']['name'],'Currency','TSh']);
            $write(['From',$statement['period']['from'],'To',$statement['period']['to'],'Timezone','Africa/Dar_es_Salaam','Basis','Recorded date']);
            $write(['Opening balance',$statement['opening_balance'],'Closing balance',$statement['closing_balance']]);
            $write(['Recorded date','Document date','Kind','Reference','Charge (TSh)','Payment (TSh)','Credit (TSh)','Balance (TSh)']);
            foreach($statement['entries'] as $entry)$write([$entry['at'],$entry['document_date'],$entry['kind'],$entry['reference'],$entry['charge'],$entry['payment'],$entry['credit'],$entry['balance']]);
            fclose($file);
        },'supplier-statement-'.$supplier->publicId().'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }
    public function preview(SupplierActionRequest $r,string $action){return ApiResponse::success($this->suppliers->preview($r->user(),$action,$r->validated()));}
    public function commit(SupplierActionRequest $r,string $action){$input=$r->validated();$key=$input['idempotency_key'];unset($input['idempotency_key']);return ApiResponse::success($this->suppliers->commit($r->user(),$action,$input,$key));}
}
