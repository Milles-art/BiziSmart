<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\RemoveProductImageRequest;
use App\Http\Requests\Products\SaveProductImageRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\ProductImageService;
use Illuminate\Http\Request;

class ProductImageController
{
    public function __construct(private ProductImageService $images) {}

    public function capabilities() { return ApiResponse::success($this->images->capabilities()); }

    public function store(SaveProductImageRequest $request, Product $product)
    {
        $product = $this->images->upload($request->user(), $product, $request->file('image'), $request->validated('expected_revision'));
        return ApiResponse::success(['product_id' => $product->publicId(), 'image' => $this->images->metadata($product)]);
    }

    public function destroy(RemoveProductImageRequest $request, Product $product)
    {
        $product = $this->images->remove($request->user(), $product, $request->validated('expected_revision'));
        return ApiResponse::success(['product_id' => $product->publicId(), 'image' => $this->images->metadata($product)]);
    }

    public function show(Request $request, Product $product, string $revision)
    {
        $image = $this->images->read($request->user(), $product, $revision);
        return response()->stream(function () use ($image) {
            try { fpassthru($image['stream']); } finally { fclose($image['stream']); }
        }, 200, ['Content-Type' => $image['mime'], 'Content-Length' => (string) $image['bytes'],
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="product-image"', 'Vary' => 'Authorization, Host']);
    }
}
