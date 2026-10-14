<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SaleDebt extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'debt_';
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','customer_id','sale_id'];
    protected function casts(): array { return ['original_amount'=>'integer','remaining_amount'=>'integer']; }
    public function sale() { return $this->belongsTo(Sale::class); }
}
