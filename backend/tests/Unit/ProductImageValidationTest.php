<?php

use App\Exceptions\ApiException;
use App\Services\ProductImageService;
use Illuminate\Http\UploadedFile;

uses(Tests\TestCase::class);

function uploadedImageBytes(string $bytes, string $name, string $declaredMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'bizi-image-');
    file_put_contents($path, $bytes);
    return new UploadedFile($path, $name, $declaredMime, null, true);
}

it('validates actual image bytes instead of trusting filename and declared MIME', function () {
    $file = uploadedImageBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS9sAAAAASUVORK5CYII='), 'misleading.jpg', 'image/jpeg');
    try {
        expect(app(ProductImageService::class)->validateBytes($file))->toMatchArray(['mime' => 'image/png', 'width' => 1, 'height' => 1]);
    } finally { unlink($file->getRealPath()); }
});

it('rejects executable, SVG and arbitrary bytes despite image extensions', function (string $bytes) {
    $file = uploadedImageBytes($bytes, 'picture.png', 'image/png');
    try { app(ProductImageService::class)->validateBytes($file); }
    finally { unlink($file->getRealPath()); }
})->with(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'php' => '<?php phpinfo();', 'text' => 'not an image'])->throws(ApiException::class);

it('rejects oversize images and exposes unavailable R2 configuration honestly', function () {
    $file = uploadedImageBytes(str_repeat('x', 2 * 1024 * 1024 + 1), 'large.png', 'image/png');
    try { expect(fn () => app(ProductImageService::class)->validateBytes($file))->toThrow(ApiException::class); }
    finally { unlink($file->getRealPath()); }
    config(['filesystems.disks.product_images' => ['driver' => 's3', 'key' => '', 'secret' => '', 'bucket' => '', 'endpoint' => '']]);
    expect(app(ProductImageService::class)->capabilities())->toMatchArray(['configured' => false, 'enabled' => false, 'reason' => 'storage_setup_required']);
});
