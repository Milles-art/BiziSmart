<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,User,Supplier,SupplierOrder,SupplierInvoice,SupplierReturn,SupplierCredit,SupplierPayment,SupplierAllocation,SupplierOperation,Product,ProductPackaging,ProductBatch,TillSession};
use App\Support\{Money,Quantity,ExactMath};
use App\Tenancy\TenantContext;
use Illuminate\Support\{Str,Facades\DB};
use Illuminate\Auth\Access\AuthorizationException;

class SupplierService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors,private StockService $stock,private TillService $tills) {}
    private function actor(User $original,string $action):User {
        $actor=$this->actors->lock($original,$action==='receive'?'receive_stock':'manage_products');
        if($action!=='receive'&&!$actor->hasRole('owner'))throw new AuthorizationException;
        if($action==='receive'&&!$actor->hasRole('owner')&&!$actor->hasRole('stockkeeper'))throw new AuthorizationException;
        Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
        return $actor;
    }
    public function index(User $original):array {
        return DB::transaction(function()use($original){$this->actor($original,'read');
            return ['suppliers'=>Supplier::orderBy('id')->get()->map(fn($s)=>$this->supplier($s))->all(),
                'orders'=>SupplierOrder::latest('id')->limit(100)->get()->map(fn($row)=>$this->order($row))->all(),
                'invoices'=>SupplierInvoice::latest('id')->limit(100)->get()->map(fn($row)=>$this->invoice($row))->all(),
                'returns'=>SupplierReturn::latest('id')->limit(100)->get()->map(fn($row)=>$this->returned($row))->all(),
                'credits'=>SupplierCredit::latest('id')->limit(100)->get()->map(fn($row)=>$this->credit($row))->all(),
                'payments'=>SupplierPayment::latest('id')->limit(100)->get()->map(fn($row)=>$this->payment($row))->all()];},3);
    }
    public function receiving(User $original):array {
        return DB::transaction(function()use($original){$this->actor($original,'receive');
            return ['orders'=>SupplierOrder::where('status','issued')->orderBy('id')->get()->map(fn($row)=>$this->order($row))->all(),
                'suppliers'=>Supplier::where('is_active',true)->get()->map(fn($s)=>['id'=>$s->publicId(),'name'=>$s->name])->all()];},3);
    }
    public function preview(User $original,string $action,array $input):array {
        return DB::transaction(function()use($original,$action,$input){$actor=$this->actor($original,$action);$review=$this->review($actor,$action,$input);$public=$review;if(isset($public['lines']))$public['lines']=$this->publicLines($public['lines']);return [...$public,'review_hash'=>SupervisorApprovalService::snapshotHash($review)];},3);
    }
    public function commit(User $original,string $action,array $input,string $key):array {
        return DB::transaction(function()use($original,$action,$input,$key){
            $actor=$this->actor($original,$action);$key=strtolower($key);$hash=SupervisorApprovalService::snapshotHash(['action'=>$action,'input'=>$input]);
            $existing=SupplierOperation::where('operation_id',$key)->lockForUpdate()->first();
            if($existing){if((int)$existing->actor_id!==(int)$actor->id||(int)$existing->device_id!==(int)$actor->currentAccessToken()->device_id||!hash_equals($existing->request_fingerprint,$hash))throw new ApiException('OPERATION_ID_REUSED','This supplier reference was used with different details.',409);return [...$existing->result,'replayed'=>true];}
            $review=$this->review($actor,$action,$input);
            if(!hash_equals(SupervisorApprovalService::snapshotHash($review),$input['review_hash']))throw new ApiException('SUPPLIER_CHANGED','Supplier details, stock or balances changed. Review again.',409);
            $result=$this->write($actor,$action,$input,$review,$key);
            SupplierOperation::create(['actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$key,'request_fingerprint'=>$hash,'action'=>$action,'result'=>$result]);
            return [...$result,'replayed'=>false];
        },3);
    }
    private function target(string $class,string $id){$row=(new $class)->resolveRouteBindingQuery($class::query(),$id)->lockForUpdate()->first();if(!$row)throw new ApiException('NOT_FOUND','The supplier record was not found.',404);return $row;}
    private function review(User $actor,string $action,array $input):array {
        try {
            if($action==='supplier'){
                $supplier=isset($input['supplier_id'])?$this->target(Supplier::class,$input['supplier_id']):null;
                if($supplier&&(int)$supplier->revision!==($input['expected_revision']??-1))throw new ApiException('SUPPLIER_CHANGED','Refresh the supplier before editing.',409);
                return ['action'=>$action,'supplier_id'=>$supplier?->publicId(),'revision'=>$supplier?->revision,'name'=>trim($input['name']),'phone'=>$input['phone']??null,'address'=>$input['address']??null,'is_active'=>$input['is_active']??true];
            }
            $supplier=$this->target(Supplier::class,$input['supplier_id']);
            if(!$supplier->is_active && in_array($action,['order','receive'],true))throw new ApiException('SUPPLIER_INACTIVE','Activate this supplier before ordering or receiving.',409);
            $review=['action'=>$action,'supplier_id'=>$supplier->publicId()];
            if(in_array($action,['order','receive'],true)){
                $order=isset($input['order_id'])?$this->target(SupplierOrder::class,$input['order_id']):null;
                if($order && (int)$order->supplier_id!==(int)$supplier->id)throw new ApiException('NOT_FOUND','Order not found for this supplier.',404);
                if($action==='order' && $order && ($order->status!=='draft'||$order->revision!==($input['expected_revision']??-1)))throw new ApiException('SUPPLIER_CHANGED','Only the current draft can be edited.',409);
                if($action==='receive' && !$actor->hasRole('owner') && !$order)throw new AuthorizationException;
                if($action==='receive' && $order && $order->status!=='issued')throw new ApiException('ORDER_NOT_ISSUED','The owner must issue this order before receiving.',409);
                $lines=$this->quoteLines($input['lines']);$total=0;foreach($lines as $line)$total=Money::amount(ExactMath::add($total,$line['amount']));
                $review+=[ 'order_id'=>$order?->publicId(),'order_revision'=>$order?->revision,'lines'=>$lines,'total'=>$total];
                if($action==='order')return [...$review,'status'=>$input['status']];
                $reference=trim($input['invoice_reference']);$hash=$this->referenceHash($reference);
                if(SupplierInvoice::where('supplier_id',$supplier->id)->where('invoice_hash',$hash)->exists())throw new ApiException('INVOICE_ALREADY_RECEIVED','This supplier invoice already has a stock receipt. Do not receive it twice.',409);
                if($order)$this->authorisedDelivery($order,$lines);
                Money::amount(ExactMath::add($this->balance(SupplierInvoice::where('supplier_id',$supplier->id)->get()),$total));
                return [...$review,'invoice_reference'=>$reference,'invoice_date'=>$input['invoice_date']];
            }
            if($action==='return'){
                $invoice=$this->target(SupplierInvoice::class,$input['invoice_id']);$this->belongs($supplier,$invoice);
                $previous=[];foreach(SupplierReturn::where('invoice_id',$invoice->id)->get() as $return)foreach($return->lines as $line)$previous[$line['line_index']]=ExactMath::add($previous[$line['line_index']]??0,$line['atoms']);
                $indices=[];$lines=[];$total=0;
                foreach($input['lines'] as $entry){$i=$entry['line_index'];if(isset($indices[$i])||!isset($invoice->lines[$i]))throw new \InvalidArgumentException('Select each original delivery line once.');$indices[$i]=true;$original=$invoice->lines[$i];
                    $batch=$this->target(ProductBatch::class,$original['batch_id']);$atoms=Quantity::parse($entry['qty'],$original['quantity_type']==='weight');$before=$previous[$i]??0;
                    if($atoms<1||ExactMath::add($before,$atoms)>$original['atoms']||$atoms>$batch->qty_remaining_atoms)throw new ApiException('INSUFFICIENT_STOCK','Return only remaining original delivery quantities.',400);
                    $amount=app(RefundCalculator::class)->line($original['amount'],$original['atoms'],$before,$atoms);$total=Money::amount(ExactMath::add($total,$amount));
                    $lines[]=['line_index'=>$i,'batch_id'=>$batch->publicId(),'product_id'=>$original['product_id'],'name'=>$original['name'],'quantity_type'=>$original['quantity_type'],'qty'=>Quantity::format($atoms,$original['quantity_type']==='weight'),'atoms'=>$atoms,'amount'=>$amount,'batch_balance'=>$batch->qty_remaining_atoms];
                }
                return [...$review,'invoice_id'=>$invoice->publicId(),'lines'=>$lines,'expected_credit'=>$total,'reason'=>trim($input['reason'])];
            }
            if(in_array($action,['payment','credit','apply_credit'],true)){
                $credit=null;$return=null;$available=null;
                if($action==='credit'){$return=$this->target(SupplierReturn::class,$input['return_id']);$this->belongs($supplier,$return);if(SupplierCredit::where('return_id',$return->id)->exists())throw new ApiException('CREDIT_ALREADY_RECORDED','This return already has an issued credit note.',409);$available=Money::amount($input['amount']);if($available<1||!($input['completed']??false))throw new \InvalidArgumentException('Record a positive supplier-issued credit note.');}
                if($action==='apply_credit'){$credit=$this->target(SupplierCredit::class,$input['credit_id']);$this->belongs($supplier,$credit);$available=$credit->remaining_amount;}
                [$allocations,$total]=$this->allocations($supplier,$input['allocations']??[]);
                if($action==='payment' && $total<1)throw new \InvalidArgumentException('Choose positive payments for specific invoices.');
                if($available!==null && $total>$available)throw new \InvalidArgumentException('Allocations cannot exceed the unapplied credit.');
                if($action==='apply_credit' && $total<1)throw new \InvalidArgumentException('Choose invoices for this credit.');
                $review+=['allocations'=>$allocations,'allocated_amount'=>$total];
                if($action==='apply_credit')return [...$review,'credit_id'=>$credit->publicId(),'credit_remaining'=>$available];
                $reference=trim($input['reference']);$referenceHash=$this->referenceHash($reference);
                $table=$action==='payment'?SupplierPayment::class:SupplierCredit::class;
                if($table::where('supplier_id',$supplier->id)->where('reference_hash',$referenceHash)->exists())throw new ApiException('REFERENCE_ALREADY_RECORDED','This supplier payment or credit reference was already recorded.',409);
                if($action==='credit'){
                    Money::amount(ExactMath::add($this->balance(SupplierCredit::where('supplier_id',$supplier->id)->get()),$available-$total));
                    return [...$review,'return_id'=>$return->publicId(),'amount'=>$available,'reference'=>$reference,'completed'=>true];
                }
                $source=$input['source'];$till=null;
                if($source==='till'){$till=$this->target(TillSession::class,$input['till_id']);if((int)$till->cashier_id!==(int)$actor->id||$till->status!=='open')throw new AuthorizationException;$cash=$this->tills->view($till)['expected_cash'];if($cash<$total)throw new ApiException('INSUFFICIENT_TILL_CASH','Your drawer does not contain enough cash for this supplier payment.',400);$review+=['till_id'=>$till->publicId(),'till_revision'=>$till->revision,'till_cash'=>$cash];}
                elseif(!($input['completed']??false))throw new \InvalidArgumentException('Confirm that this external payment has already been completed.');
                return [...$review,'source'=>$source,'reference'=>$reference,'paid_on'=>$input['paid_on'],'amount'=>$total,'external_transfer_executed'=>false];
            }
            throw new \InvalidArgumentException('Unsupported supplier action.');
        }catch(\InvalidArgumentException|\OverflowException $e){throw new ApiException('VALIDATION_ERROR',$e->getMessage(),422);}
    }
    private function quoteLines(array $entries):array {
        $result=[];
        foreach($entries as $entry){$product=$this->target(Product::class,$entry['product_id']);if($product->trashed()||!$product->is_active)throw new ApiException('PRODUCT_INACTIVE','Receive only active products.',409);
            $pack=isset($entry['packaging_id'])?$this->target(ProductPackaging::class,$entry['packaging_id']):null;
            if($pack&&((int)$pack->product_id!==(int)$product->id||!$pack->is_active||$pack->archived_at))throw new ApiException('PACKAGING_VERSION_CHANGED','Select the current product package.',409);
            $entered=Quantity::parse($entry['qty'],!$pack&&$product->quantity_type==='weight');if($entered<1)throw new \InvalidArgumentException('Receive positive quantities.');
            $atoms=$pack?Quantity::packageAtoms($entry['qty'],$pack->conversion_atoms):$entered;$cost=Money::amount($entry['cost_price']);
            $result[]=['product_id'=>$product->publicId(),'name'=>$product->name,'packaging_id'=>$pack?->publicId(),'packaging_name'=>$pack?->name,'qty'=>Quantity::format($entered,!$pack&&$product->quantity_type==='weight'),'base_qty'=>Quantity::format($atoms,$product->quantity_type==='weight'),'atoms'=>$atoms,'quantity_type'=>$product->quantity_type,'unit'=>$product->unit,'cost_price'=>$cost,'amount'=>Money::lineTotal($atoms,$cost,$product->quantity_type==='weight'),...array_intersect_key($entry,array_flip(['batch_number','expiry_date']))];
        }
        return $result;
    }
    private function authorisedDelivery(SupplierOrder $order,array $lines):void {
        $allowed=[];foreach($order->lines as $line){$key=$line['product_id'].'|'.($line['packaging_id']??'').'|'.$line['cost_price'];$allowed[$key]=ExactMath::add($allowed[$key]??0,$line['atoms']);}
        foreach(SupplierInvoice::where('order_id',$order->id)->get() as $invoice)foreach($invoice->lines as $line){$key=$line['product_id'].'|'.($line['packaging_id']??'').'|'.$line['cost_price'];$allowed[$key]=ExactMath::add($allowed[$key]??0,-$line['atoms']);}
        foreach($lines as $line){$key=$line['product_id'].'|'.($line['packaging_id']??'').'|'.$line['cost_price'];if(($allowed[$key]??0)<$line['atoms'])throw new ApiException('ORDER_QUANTITY_EXCEEDED','Receive only the remaining goods and exact unit costs on the issued order.',409);$allowed[$key]-=$line['atoms'];}
    }
    private function belongs(Supplier $supplier,$row):void {if((int)$row->supplier_id!==(int)$supplier->id)throw new ApiException('NOT_FOUND','Record not found for this supplier.',404);}
    private function allocations(Supplier $supplier,array $entries):array {
        $rows=[];$total=0;$seen=[];
        usort($entries,fn($a,$b)=>strcmp($a['invoice_id'],$b['invoice_id']));
        foreach($entries as $entry){if(isset($seen[$entry['invoice_id']]))throw new \InvalidArgumentException('Select each invoice once.');$seen[$entry['invoice_id']]=true;
            $invoice=$this->target(SupplierInvoice::class,$entry['invoice_id']);$this->belongs($supplier,$invoice);$amount=Money::amount($entry['amount']);if($amount<1||$amount>$invoice->remaining_amount)throw new ApiException('PAYABLE_CHANGED','Payment exceeds an invoice remaining amount.',409);
            $total=Money::amount(ExactMath::add($total,$amount));$rows[]=['invoice_id'=>$invoice->publicId(),'amount'=>$amount,'remaining_amount'=>$invoice->remaining_amount];}
        return [$rows,$total];
    }
    private function write(User $actor,string $action,array $input,array $review,string $key):array {
        if($action==='supplier'){$supplier=isset($input['supplier_id'])?$this->target(Supplier::class,$input['supplier_id']):new Supplier;$supplier->fill(array_intersect_key($review,array_flip(['name','phone','address','is_active'])));$supplier->revision=($supplier->revision??0)+1;$supplier->save();return ['supplier'=>$this->supplier($supplier)];}
        $supplier=$this->target(Supplier::class,$input['supplier_id']);
        if($action==='order'){$order=isset($input['order_id'])?$this->target(SupplierOrder::class,$input['order_id']):new SupplierOrder;$order->fill(['supplier_id'=>$supplier->id,'actor_id'=>$actor->id,'lines'=>$review['lines'],'total'=>$review['total'],'status'=>$review['status'],'revision'=>($order->revision??0)+1,'issued_at'=>$review['status']==='issued'?now():null]);$order->save();return ['order'=>$this->order($order)];}
        if($action==='receive'){
            $lines=[];foreach($review['lines'] as $line){$receipt=$this->stock->receive($actor,$this->target(Product::class,$line['product_id']),['operation_id'=>(string)Str::uuid(),'qty'=>$line['qty'],'packaging_id'=>$line['packaging_id'],'cost_price'=>$line['cost_price'],'batch_number'=>$line['batch_number'],'expiry_date'=>$line['expiry_date']??null]);$lines[]=[...$line,'batch_id'=>$receipt['batch']->publicId()];}
            $invoice=SupplierInvoice::create(['supplier_id'=>$supplier->id,'order_id'=>isset($input['order_id'])?$this->target(SupplierOrder::class,$input['order_id'])->id:null,'actor_id'=>$actor->id,'invoice_reference'=>$review['invoice_reference'],'invoice_hash'=>$this->referenceHash($review['invoice_reference']),'original_amount'=>$review['total'],'remaining_amount'=>$review['total'],'lines'=>$lines,'invoice_date'=>$review['invoice_date']]);return ['invoice'=>$this->invoice($invoice)];
        }
        if($action==='return'){
            foreach($review['lines'] as $line)$this->stock->returnToSupplier($actor,$this->target(ProductBatch::class,$line['batch_id']),$line['atoms'],(string)Str::uuid(),$review['reason']);
            $return=SupplierReturn::create(['supplier_id'=>$supplier->id,'invoice_id'=>$this->target(SupplierInvoice::class,$review['invoice_id'])->id,'actor_id'=>$actor->id,'lines'=>$review['lines'],'reason'=>$review['reason'],'expected_credit'=>$review['expected_credit']]);return ['return'=>$this->returned($return)];
        }
        $payment=null;$credit=null;
        if($action==='payment'){
            $till=isset($review['till_id'])?$this->target(TillSession::class,$review['till_id']):null;
            if($till)$this->tills->recordCash($actor,$till,'paid_out',$review['amount'],$key,'Supplier payment: '.$supplier->publicId());
            $payment=SupplierPayment::create(['supplier_id'=>$supplier->id,'actor_id'=>$actor->id,'till_session_id'=>$till?->id,'source'=>$review['source'],'amount'=>$review['amount'],'reference'=>$review['reference'],'reference_hash'=>$this->referenceHash($review['reference']),'paid_on'=>$review['paid_on']]);
        }elseif($action==='credit')$credit=SupplierCredit::create(['supplier_id'=>$supplier->id,'return_id'=>$this->target(SupplierReturn::class,$review['return_id'])->id,'actor_id'=>$actor->id,'reference'=>$review['reference'],'reference_hash'=>$this->referenceHash($review['reference']),'amount'=>$review['amount'],'remaining_amount'=>$review['amount']]);
        else $credit=$this->target(SupplierCredit::class,$review['credit_id']);
        foreach($review['allocations'] as $allocation){$invoice=$this->target(SupplierInvoice::class,$allocation['invoice_id']);$invoice->remaining_amount-=$allocation['amount'];$invoice->save();SupplierAllocation::create(['supplier_id'=>$supplier->id,'invoice_id'=>$invoice->id,'payment_id'=>$payment?->id,'credit_id'=>$credit?->id,'amount'=>$allocation['amount']]);}
        if($credit){$credit->remaining_amount-=$review['allocated_amount'];$credit->save();return ['credit'=>$this->credit($credit)];}
        return ['payment'=>$this->payment($payment),'external_transfer_executed'=>false];
    }
    private function referenceHash(string $value):string{return hash_hmac('sha256',strtolower(trim($value)),config('app.key'));}
    private function balance($rows):int {$total=0;foreach($rows as $row)$total=Money::amount(ExactMath::add($total,$row->remaining_amount));return $total;}
    private function supplier(Supplier $s):array{return ['id'=>$s->publicId(),'name'=>$s->name,'phone'=>$s->phone,'address'=>$s->address,'is_active'=>$s->is_active,'revision'=>$s->revision,'payable'=>$this->balance(SupplierInvoice::where('supplier_id',$s->id)->get()),'unapplied_credit'=>$this->balance(SupplierCredit::where('supplier_id',$s->id)->get())];}
    private function publicLines(array $lines):array {return array_map(function($line){unset($line['atoms'],$line['batch_balance']);return $line;},$lines);}
    private function order(SupplierOrder $o):array{return ['id'=>$o->publicId(),'supplier_id'=>Supplier::findOrFail($o->supplier_id)->publicId(),'status'=>$o->status,'revision'=>$o->revision,'lines'=>$this->publicLines($o->lines),'total'=>$o->total,'issued_at'=>$o->issued_at?->toISOString()];}
    private function invoice(SupplierInvoice $i):array{return ['id'=>$i->publicId(),'supplier_id'=>Supplier::findOrFail($i->supplier_id)->publicId(),'order_id'=>$i->order_id?SupplierOrder::findOrFail($i->order_id)->publicId():null,'reference'=>$i->invoice_reference,'invoice_date'=>$i->invoice_date->toDateString(),'original_amount'=>$i->original_amount,'remaining_amount'=>$i->remaining_amount,'lines'=>$this->publicLines($i->lines)];}
    private function returned(SupplierReturn $r):array{return ['id'=>$r->publicId(),'supplier_id'=>Supplier::findOrFail($r->supplier_id)->publicId(),'invoice_id'=>SupplierInvoice::findOrFail($r->invoice_id)->publicId(),'expected_credit'=>$r->expected_credit,'reason'=>$r->reason,'lines'=>$this->publicLines($r->lines),'credit_recorded'=>SupplierCredit::where('return_id',$r->id)->exists()];}
    private function credit(SupplierCredit $c):array{return ['id'=>$c->publicId(),'supplier_id'=>Supplier::findOrFail($c->supplier_id)->publicId(),'return_id'=>SupplierReturn::findOrFail($c->return_id)->publicId(),'amount'=>$c->amount,'remaining_amount'=>$c->remaining_amount,'reference'=>$c->reference];}
    private function payment(SupplierPayment $p):array{return ['id'=>$p->publicId(),'supplier_id'=>Supplier::findOrFail($p->supplier_id)->publicId(),'source'=>$p->source,'amount'=>$p->amount,'reference'=>$p->reference,'paid_on'=>$p->paid_on->toDateString()];}
}
