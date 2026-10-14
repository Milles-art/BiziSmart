<?php
namespace App\Services;
use App\Models\{Business,User,IncentiveEntry};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
class CommissionLeaderboardService {
 public function __construct(private IncentiveService $incentives,private FinancialActorService $actors,private TenantContext $context){}
 public function report(User $original,int $page,int $size):array {return DB::transaction(function()use($original,$page,$size){$actor=$this->actors->lock($original,'view_reports');abort_unless($actor->hasRole('owner'),403);Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$rows=User::whereIn('id',IncentiveEntry::select('cashier_id'))->orderBy('id')->get()->map(fn($user)=>$this->incentives->commission($user))->all();usort($rows,fn($a,$b)=>($b['earned']<=>$a['earned'])?:strcmp($a['cashier_id'],$b['cashier_id']));$previous=null;$rank=0;foreach($rows as$i=>&$row){if($previous!==$row['earned'])$rank=$i+1;$previous=$row['earned'];$row['rank']=$rank;}unset($row);return ['leaderboard'=>array_slice($rows,($page-1)*$size,$size),'basis'=>'all_time_net_earned_commission','meta'=>['page'=>$page,'per_page'=>$size,'total'=>count($rows)]];});}
}
