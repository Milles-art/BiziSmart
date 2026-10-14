<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Branch, CashDrop, Department, Product, ProductBatch, Sale, SaleItem, SaleReturn, SaleReturnItem, TillCashEntry, TillSession};
use App\Support\{ExactMath, Money, Quantity, ReportMath, ReportPeriod};
use Illuminate\Support\Facades\DB;

/** Financial reports use saved receipts and journal events; department classification is explicitly current. */
class DepartmentTillReportService
{
    public function departments(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $period = new ReportPeriod($filters['from'], $filters['to']);
            $selected = isset($filters['department_id']) ? (new Department)->resolveRouteBinding($filters['department_id']) : null;
            if (isset($filters['department_id']) && !$selected) throw new ApiException('NOT_FOUND', 'Department not found.', 404);
            $branch = $this->branch($filters);
            $rows = [];
            $classify = function (Product $product) use (&$rows): string {
                $department = $product->department;
                $key = $department?->publicId() ?? 'unassigned';
                $rows[$key] ??= ['id'=>$department?->publicId(), 'name'=>$department?->name ?? 'Unassigned', 'sales_gross'=>0, 'refunds_gross'=>0, 'output_vat'=>0, 'refund_vat'=>0, 'cogs'=>0, 'returned_cogs'=>0, 'recorded_stock_value'=>0, 'negative_batches'=>0];
                return $key;
            };
            $accept = fn(Product $product) => !$selected || (int)$product->department_id === (int)$selected->id;
            $sales = Sale::with(['items.product.department','items.batch'])->where(function ($q) use ($period) {
                $q->whereBetween('occurred_at', [$period->start, $period->until])->where('occurred_at','<',$period->until)
                    ->orWhere(fn($q)=>$q->whereNull('occurred_at')->where('created_at','>=',$period->start)->where('created_at','<',$period->until));
            });
            if ($branch) $sales->where('branch_id',$branch->id);
            $this->bounded($sales);
            $sales->chunkById(100,function($sales)use(&$rows,$classify,$accept){foreach($sales as $sale){
                $prefix=0;
                foreach($sale->lines as $index=>$line){
                    $items=$sale->items->where('line_index',$index); $product=$items->first()?->product;
                    $vat=ReportMath::reversal($sale->vat_amount,$sale->total,$prefix,$line['line_total']);
                    $prefix=ReportMath::add($prefix,$line['line_total']);
                    if(!$product || !$accept($product)) continue;
                    $key=$classify($product); $this->add($rows[$key],'sales_gross',$line['line_total']); $this->add($rows[$key],'output_vat',$vat);
                    foreach($items as $item) $this->add($rows[$key],'cogs',Money::lineTotal($item->qty_atoms,$item->cost_price??$item->batch->cost_price,$line['quantity_type']==='weight'));
                }
            }});
            $returns=SaleReturn::where('created_at','>=',$period->start)->where('created_at','<',$period->until);
            if($branch) $returns->whereIn('sale_id',Sale::where('branch_id',$branch->id)->select('id'));
            $this->bounded($returns);
            $returns->chunkById(100,function($returns)use(&$rows,$classify,$accept){foreach($returns as $return){
                $sale=Sale::findOrFail($return->sale_id);
                $previous=Money::amount(Quantity::parse((string)SaleReturn::where('sale_id',$sale->id)->where('id','<',$return->id)->sum('refund_total')));
                $refundVat=ReportMath::reversal($sale->vat_amount,$sale->total,$previous,$return->refund_total); $prefix=0;
                foreach($return->lines as $line){
                    $item=SaleItem::with('product.department')->where('sale_id',$sale->id)->where('line_index',$line['line_index'])->firstOrFail();
                    $amount=$line['amount']; $vat=ReportMath::reversal($refundVat,$return->refund_total,$prefix,$amount); $prefix=ReportMath::add($prefix,$amount);
                    if(!$accept($item->product))continue; $key=$classify($item->product);
                    $this->add($rows[$key],'refunds_gross',$amount); $this->add($rows[$key],'refund_vat',$vat);
                }
                SaleReturnItem::where('sale_return_id',$return->id)->chunkById(100,function($items)use(&$rows,$sale,$classify,$accept){foreach($items as $returned){
                    $original=SaleItem::with(['product.department','batch'])->findOrFail($returned->sale_item_id); if(!$accept($original->product))continue;
                    $cost=Money::lineTotal($original->qty_atoms,$original->cost_price??$original->batch->cost_price,$sale->lines[$original->line_index]['quantity_type']==='weight');
                    $before=Quantity::parse((string)SaleReturnItem::where('sale_item_id',$original->id)->where('id','<',$returned->id)->sum('qty_atoms'));
                    $key=$classify($original->product); $this->add($rows[$key],'returned_cogs',ReportMath::reversal($cost,$original->qty_atoms,$before,$returned->qty_atoms));
                }});
            }});
            // Inventory is business-wide: this product/batch schema has no branch ownership.
            ProductBatch::with('product.department')->chunkById(100,function($batches)use(&$rows,$classify,$accept){foreach($batches as $batch){
                if(!$accept($batch->product))continue; $key=$classify($batch->product);
                if($batch->qty_remaining_atoms<0){$this->add($rows[$key],'negative_batches',1);continue;}
                $this->add($rows[$key],'recorded_stock_value',Money::lineTotal($batch->qty_remaining_atoms,$batch->cost_price,$batch->product->quantity_type==='weight'));
            }});
            foreach($rows as &$row){$row['net_revenue']=ReportMath::add(ReportMath::add($row['sales_gross'],-$row['refunds_gross']),-ReportMath::add($row['output_vat'],-$row['refund_vat']));$row['net_cogs']=ReportMath::add($row['cogs'],-$row['returned_cogs']);$row['gross_profit']=ReportMath::add($row['net_revenue'],-$row['net_cogs']);}
            return ['departments'=>array_values($rows),'classification'=>'current_product_department','stock_scope'=>'business_current','period'=>['from'=>$period->from,'to'=>$period->to],'as_of'=>now()->toISOString()];
        });
    }

    public function tills(array $filters): array
    {
        return DB::transaction(function()use($filters){
            $period=new ReportPeriod($filters['from'],$filters['to']); $branch=$this->branch($filters);
            $query=TillSession::with(['branch','cashier'])->where('opened_at','<',$period->until)->where(fn($q)=>$q->whereNull('closed_at')->orWhere('closed_at','>=',$period->start));
            if($branch)$query->where('branch_id',$branch->id);
            $page=$query->orderByDesc('opened_at')->orderByDesc('id')->paginate($filters['per_page']??50);
            $rows=[];
            foreach($page as $till){
                $totals=[];TillCashEntry::where('till_session_id',$till->id)->where('created_at','>=',$period->start)->where('created_at','<',$period->until)->chunkById(100,function($entries)use(&$totals){foreach($entries as $entry)$totals[$entry->type]=ReportMath::add($totals[$entry->type]??0,$entry->amount);});
                $drops=['pending'=>0,'acknowledged'=>0,'reversed'=>0];CashDrop::where('till_session_id',$till->id)->where('created_at','>=',$period->start)->where('created_at','<',$period->until)->chunkById(100,function($records)use(&$drops){foreach($records as $drop)$drops[$drop->status]=ReportMath::add($drops[$drop->status],$drop->amount);});
                $rows[]=[...app(TillService::class)->view($till),'period_cash_entries'=>$totals,'period_cash_drops'=>$drops];
            }
            return ['tills'=>$rows,'meta'=>['page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total()],'period'=>['from'=>$period->from,'to'=>$period->to],'cash_basis'=>'entries_in_period; drawer_cash_entire_session','drop_basis'=>'handover_created_in_period; current_status'];
        });
    }
    private function branch(array $filters): ?Branch {if(!isset($filters['branch_id']))return null;return (new Branch)->resolveRouteBindingQuery(Branch::query(),$filters['branch_id'])->firstOrFail();}
    private function add(array &$row,string $key,int $amount): void {$row[$key]=ReportMath::add($row[$key],$amount);}
    private function bounded($query): void {if((clone $query)->limit(10001)->get(['id'])->count()>10000)throw new ApiException('REPORT_TOO_LARGE','Choose a narrower report period.',422);}
}

