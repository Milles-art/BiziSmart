<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
class CustomerResource extends JsonResource
{
    public function toArray($request): array { return ['id'=>$this->publicId(),'name'=>$this->name,'phone'=>$this->phone,
        'credit_limit'=>$this->credit_limit,'outstanding_balance'=>$this->outstanding_balance,'created_at'=>$this->created_at->toISOString()]; }
}
