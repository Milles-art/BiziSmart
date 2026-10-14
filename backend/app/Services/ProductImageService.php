<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImageCleanup;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImageService
{
    private bool $r2MiddlewareReady = false;
    public function __construct(private TenantContext $context) {}

    public function validatedImage(UploadedFile $file):array { return $this->validateBytes($file); }
    public function privateStorage():Filesystem { return $this->disk(); }

    public function capabilities(): array
    {
        $ready = $this->configured();
        return ['configured' => $ready, 'enabled' => $ready, 'max_bytes' => (int) config('product_images.max_bytes'),
            'formats' => ['image/jpeg', 'image/png', 'image/webp'], 'reason' => $ready ? 'ready' : 'storage_setup_required'];
    }

    public function metadata(Product $product): ?array
    {
        $product->assertCurrentBusiness();
        if (! $product->image_path) { return null; }
        return ['revision' => $this->revision($product), 'mime' => $product->image_mime,
            'bytes' => (int) $product->image_bytes, 'width' => (int) $product->image_width, 'height' => (int) $product->image_height];
    }

    public function upload(User $actor, Product $product, UploadedFile $file, ?string $expectedRevision): Product
    {
        $product->assertCurrentBusiness();
        $this->readAuthority($actor, $product, true);
        $metadata = $this->validateBytes($file);
        $disk = $this->disk();
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$metadata['mime']];
        $path = 'businesses/'.$this->context->requireId().'/products/'.$product->publicId().'/'.Str::ulid().'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if ($stream === false || ! $disk->put($path, $stream, ['visibility' => 'private', 'ContentType' => $metadata['mime']])) {
                throw new \RuntimeException('Image upload failed.');
            }
        } catch (Throwable) {
            $this->cleanup($disk, $path);
            throw new ApiException('IMAGE_STORAGE_UNAVAILABLE', 'The image could not be stored. Please try again.', 503);
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
        $old = null;
        try {
            DB::transaction(function () use ($actor, $product, $metadata, $path, $expectedRevision, &$old) {
                $this->lockAuthority($actor);
                $product->refreshForUpdate();
                $this->checkProduct($product, $actor);
                if ($this->revision($product) !== $expectedRevision) {
                    throw new ApiException('IMAGE_CHANGED', 'The product image changed. Refresh before replacing it.', 409);
                }
                $old = $product->image_path;
                if ($old) { ProductImageCleanup::firstOrCreate(['path' => $old]); }
                $product->forceFill(['image_path' => $path, 'image_mime' => $metadata['mime'], 'image_bytes' => $metadata['bytes'],
                    'image_width' => $metadata['width'], 'image_height' => $metadata['height']])->save();
                activity('products')->performedOn($product)->causedBy($actor)->event('product_image_updated')
                    ->withProperties(['product_id' => $product->publicId()])->log('Product image updated');
            }, 3);
        } catch (Throwable $error) {
            $this->cleanup($disk, $path);
            throw $error;
        }
        if ($old) { $this->cleanup($disk, $old); }
        return $product;
    }

    public function remove(User $actor, Product $product, string $expectedRevision): Product
    {
        $product->assertCurrentBusiness();
        $this->readAuthority($actor, $product, true);
        $disk = $this->disk();
        $old = DB::transaction(function () use ($actor, $product, $expectedRevision) {
            $this->lockAuthority($actor);
            $product->refreshForUpdate();
            $this->checkProduct($product, $actor);
            if ($product->image_path === null) { return null; } // A retry after successful removal is harmless.
            if ($this->revision($product) !== $expectedRevision) {
                throw new ApiException('IMAGE_CHANGED', 'The product image changed. Refresh before removing it.', 409);
            }
            $old = $product->image_path;
            ProductImageCleanup::firstOrCreate(['path' => $old]);
            $product->forceFill(['image_path' => null, 'image_mime' => null, 'image_bytes' => null, 'image_width' => null, 'image_height' => null])->save();
            activity('products')->performedOn($product)->causedBy($actor)->event('product_image_removed')
                ->withProperties(['product_id' => $product->publicId()])->log('Product image removed');
            return $old;
        }, 3);
        if ($old) { $this->cleanup($disk, $old); }
        return $product;
    }

    /** @return array{stream:resource,mime:string,bytes:int} */
    public function read(User $actor, Product $product, string $revision): array
    {
        $product->assertCurrentBusiness();
        $this->readAuthority($actor, $product);
        if (! $product->image_path || ! hash_equals($this->revision($product) ?? '', $revision)) {
            throw new ApiException('NOT_FOUND', 'The product image was not found.', 404);
        }
        $prefix = 'businesses/'.$this->context->requireId().'/products/'.$product->publicId().'/';
        if (! str_starts_with($product->image_path, $prefix) || str_contains($product->image_path, '..')) {
            throw new ApiException('NOT_FOUND', 'The product image was not found.', 404);
        }
        try { $stream = $this->disk()->readStream($product->image_path); }
        catch (ApiException $error) { throw $error; }
        catch (Throwable) { throw new ApiException('IMAGE_STORAGE_UNAVAILABLE', 'The image is temporarily unavailable.', 503); }
        if (! is_resource($stream)) { throw new ApiException('NOT_FOUND', 'The product image was not found.', 404); }
        return ['stream' => $stream, 'mime' => $product->image_mime, 'bytes' => (int) $product->image_bytes];
    }

    public function validateBytes(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > config('product_images.max_bytes') || $file->getSize() < 1) {
            throw new ApiException('VALIDATION_ERROR', 'Choose an image smaller than 2 MB.', 422, 'image');
        }
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! $size || ($size['mime'] ?? '') !== $mime
            || $size[0] < 1 || $size[1] < 1 || $size[0] > config('product_images.max_dimension') || $size[1] > config('product_images.max_dimension')
            || $size[0] * $size[1] > config('product_images.max_pixels')) {
            throw new ApiException('VALIDATION_ERROR', 'Choose a valid JPEG, PNG or WebP image up to 4096 pixels per side.', 422, 'image');
        }
        return ['mime' => $mime, 'width' => $size[0], 'height' => $size[1], 'bytes' => $file->getSize()];
    }

    private function revision(Product $product): ?string { return $product->image_path ? pathinfo($product->image_path, PATHINFO_FILENAME) : null; }

    private function configured(): bool
    {
        $disk = config('filesystems.disks.'.config('product_images.disk'));
        if (app()->environment('testing') && ($disk['driver'] ?? '') === 'local') { return true; }
        $endpoint = $disk['endpoint'] ?? '';
        return ($disk['driver'] ?? '') === 's3' && class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)
            && ! empty($disk['key']) && ! empty($disk['secret']) && ! empty($disk['bucket'])
            && preg_match('~^https://[a-z0-9]+\.r2\.cloudflarestorage\.com/?$~D', $endpoint) === 1;
    }

    private function disk(): Filesystem
    {
        if (! $this->configured()) {
            throw new ApiException('IMAGE_STORAGE_UNCONFIGURED', 'Product image storage is not configured. Ask the owner to connect private R2 storage.', 503);
        }
        $disk = Storage::disk(config('product_images.disk'));
        if (! $this->r2MiddlewareReady && method_exists($disk, 'getClient')) {
            // R2 has bucket-level privacy and does not implement S3 object ACLs.
            $disk->getClient()->getHandlerList()->appendInit(\Aws\Middleware::mapCommand(function (\Aws\CommandInterface $command) {
                unset($command['ACL']);
                return $command;
            }), 'r2-private-bucket');
            $this->r2MiddlewareReady = true;
        }
        return $disk;
    }

    private function readAuthority(User $actor, Product $product, bool $manage = false): void
    {
        $actor->refresh(); $actor->unsetRelation('roles')->unsetRelation('permissions');
        if (! $actor->is_active || ($manage ? ! $actor->can('manage_products') : ! ($actor->can('manage_products') || $actor->can('sell') || $actor->can('receive_stock')))) {
            throw new AuthorizationException;
        }
        $this->checkProduct($product, $actor);
    }

    private function lockAuthority(User $actor): void
    {
        $actor->refreshForUpdate(); $actor->unsetRelation('roles')->unsetRelation('permissions');
        $business = Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (! $actor->is_active || ! $business->is_active || ! $actor->can('manage_products')) { throw new AuthorizationException; }
    }

    private function checkProduct(Product $product, User $actor): void
    {
        if ($product->trashed() || (! $product->is_active && ! $actor->can('manage_products'))) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }
    }

    private function cleanup(Filesystem $disk, string $path): void
    {
        if (! str_starts_with($path, 'businesses/'.$this->context->requireId().'/products/') || str_contains($path, '..')) {
            Log::warning('Invalid tenant product image cleanup was blocked.', ['business_id' => $this->context->requireId()]);
            return;
        }
        try {
            if ($disk->delete($path)) { ProductImageCleanup::where('path', $path)->delete(); return; }
        } catch (Throwable) { /* Preserve authoritative committed metadata. */ }
        try { ProductImageCleanup::firstOrCreate(['path' => $path]); } catch (Throwable) { /* An unavailable DB cannot undo object storage. */ }
        Log::warning('Product image cleanup requires retry.', ['business_id' => $this->context->requireId(), 'object' => hash('sha256', $path)]);
    }

    public function retryCleanups(): int
    {
        $disk = $this->disk(); $count = 0;
        ProductImageCleanup::orderBy('id')->chunkById(50, function ($rows) use ($disk, &$count) {
            foreach ($rows as $row) {
                if (! str_starts_with($row->path, 'businesses/'.$this->context->requireId().'/products/') || str_contains($row->path, '..')) { continue; }
                // Retained archive images and any currently referenced image must never be removed.
                if (Product::withTrashed()->where('image_path', $row->path)->exists()) { continue; }
                $this->cleanup($disk, $row->path); $count++;
            }
        });
        return $count;
    }
}
