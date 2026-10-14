<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
class TillSession extends Model {
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'till_';
    protected $guarded = ['id','business_id','public_id','open_cashier_id'];
    protected $hidden = ['id','business_id','branch_id','cashier_id','approved_by','open_cashier_id'];
    protected function casts(): array { return ['opening_float'=>'integer','revision'=>'integer','closing_cash'=>'integer','expected_cash'=>'integer','variance'=>'integer','variance_limit'=>'integer','opened_at'=>'datetime','closed_at'=>'datetime','owner_review_required'=>'boolean']; }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function cashier() { return $this->belongsTo(User::class,'cashier_id')->withTrashed(); }
    public function approver() { return $this->belongsTo(User::class,'approved_by')->withTrashed(); }
    protected static function booted(): void {
        static::updating(function ($till) { if ($till->getOriginal('status') === 'closed') { throw new \LogicException('Closed tills are immutable.'); } });
        static::deleting(fn () => throw new \LogicException('Till history must be retained.'));
    }
}
