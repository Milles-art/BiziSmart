<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
class TillResource extends JsonResource {
    public function toArray(Request $request): array { return app(\App\Services\TillService::class)->view($this->resource,$request->user()); }
}
