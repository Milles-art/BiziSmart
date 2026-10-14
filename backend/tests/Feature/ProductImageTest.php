<?php

use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\StaffRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test', 'filesystems.disks.product_images' => ['driver' => 'local', 'root' => storage_path('framework/testing/product-images'), 'throw' => true]]);
    Storage::fake('product_images');
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'images', 'name' => 'Images', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $device = Device::create(['device_uid' => 'images-device', 'name' => 'Test device', 'status' => 'approved']);
            foreach (['owner', 'cashier'] as $index => $role) {
                $user = User::create(['name' => $role, 'phone' => '071000000'.$index, 'pin_hash' => '2468', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') { $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save(); }
                $token = $user->createToken('images', ['*'], now()->addDay());
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$label.'Product'} = Product::create(['name' => 'Water', 'quantity_type' => 'counted', 'unit' => 'bottle', 'buying_price' => 500, 'selling_price' => 1000]);
        });
    }
    $this->withToken($this->alphaOwnerToken);
    $this->files = [];
    $this->imageFile = function () {
        $path = tempnam(sys_get_temp_dir(), 'bizi-image-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS9sAAAAASUVORK5CYII='));
        $this->files[] = $path;
        return new UploadedFile($path, 'water.png', 'image/png', null, true);
    };
    $this->url = 'http://alpha.bizismart.test/api/v1/products/'.$this->alphaProduct->publicId().'/image';
});

afterEach(function () { foreach ($this->files as $file) { if (is_file($file)) unlink($file); } $this->context->clear(); });

it('uploads privately, reads authenticated bytes, replaces safely and removes once', function () {
    $image = $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null], ['Accept' => 'application/json'])->assertOk()->json('data.image');
    expect($image['mime'])->toBe('image/png');
    $response = $this->get($this->url.'/'.$image['revision'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $this->context->run($this->alpha, function () {
        $product = $this->alphaProduct->fresh();
        expect($product->image_path)->toStartWith('businesses/'.$this->alpha->id.'/products/');
        Storage::disk('product_images')->assertExists($product->image_path);
        $this->oldPath = $product->image_path;
    });
    $next = $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => $image['revision']], ['Accept' => 'application/json'])->assertOk()->json('data.image');
    expect($next['revision'])->not->toBe($image['revision']);
    Storage::disk('product_images')->assertMissing($this->oldPath);
    $this->get($this->url.'/'.$image['revision'])->assertNotFound();
    $this->deleteJson($this->url, ['expected_revision' => $next['revision']])->assertOk()->assertJsonPath('data.image', null);
    $this->deleteJson($this->url, ['expected_revision' => $next['revision']])->assertOk();
    expect(Storage::disk('product_images')->allFiles())->toBe([]);
});

it('rejects stale revisions, cross-business targets and cashier uploads without orphan objects', function () {
    $image = $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null], ['Accept' => 'application/json'])->assertOk()->json('data.image');
    $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'IMAGE_CHANGED');
    expect(Storage::disk('product_images')->allFiles())->toHaveCount(1);
    $this->withToken($this->alphaCashierToken)->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => $image['revision']], ['Accept' => 'application/json'])->assertForbidden();
    $this->withToken($this->betaOwnerToken)->get('http://beta.bizismart.test/api/v1/products/'.$this->alphaProduct->publicId().'/image/'.$image['revision'])->assertNotFound();
    $this->withToken($this->alphaOwnerToken)->deleteJson('http://alpha.bizismart.test/api/v1/products/'.$this->alphaProduct->publicId())->assertOk();
    $this->get($this->url.'/'.$image['revision'])->assertNotFound();
    $this->context->run($this->alpha, fn () => expect(Product::withTrashed()->findOrFail($this->alphaProduct->id)->image_path)->not->toBeNull());
});

it('fails honestly when storage is unconfigured and rejects forged tenancy and SVG files', function () {
    $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null, 'business_id' => $this->beta->id], ['Accept' => 'application/json'])->assertUnprocessable();
    config(['filesystems.disks.product_images' => ['driver' => 's3', 'key' => '', 'secret' => '', 'bucket' => '', 'endpoint' => '']]);
    $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('error.code', 'IMAGE_STORAGE_UNCONFIGURED');
    expect(Storage::disk('product_images')->allFiles())->toBe([]);
});

it('preserves the previous image on upload failure', function () {
    $image = $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => null], ['Accept' => 'application/json'])->assertOk()->json('data.image');
    $realDisk = Storage::disk('product_images');
    $this->context->run($this->alpha, fn () => $this->oldPath = $this->alphaProduct->fresh()->image_path);
    $disk = Mockery::mock($realDisk)->makePartial();
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('product_images')->andReturn($disk);
    $this->post($this->url, ['image' => ($this->imageFile)(), 'expected_revision' => $image['revision']], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('error.code', 'IMAGE_STORAGE_UNAVAILABLE');
    $this->context->run($this->alpha, fn () => expect($this->alphaProduct->fresh()->image_path)->toBe($this->oldPath));
    $realDisk->assertExists($this->oldPath);
});
