<?php

namespace Tests\Unit;

use App\Support\StorageAssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageAssetUrlTest extends TestCase
{
    public function test_local_storage_url_keeps_configured_host_by_default(): void
    {
        config([
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.url' => 'https://api.erp.example.com/storage',
        ]);
        Storage::forgetDisk('public');

        $request = Request::create('http://nextjs.internal:8000/api/public/vehicles', 'GET');

        $this->assertSame(
            'https://api.erp.example.com/storage/vehicles/1/photo.webp',
            StorageAssetUrl::forRequest($request, 'public', 'vehicles/1/photo.webp'),
        );
    }

    public function test_local_storage_url_can_follow_request_origin_when_explicitly_enabled(): void
    {
        config([
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.url' => 'http://100.112.1.114:8000/storage',
        ]);
        Storage::forgetDisk('public');

        $request = Request::create('http://192.168.0.40:8000/api/vehicles', 'GET');

        $this->assertSame(
            'http://192.168.0.40:8000/storage/vehicles/1/photo.webp',
            StorageAssetUrl::forRequest(
                $request,
                'public',
                'vehicles/1/photo.webp',
                followRequestOrigin: true,
            ),
        );
    }

    public function test_request_origin_override_uses_https_request_scheme(): void
    {
        config([
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.url' => 'http://100.112.1.114:8000/storage',
        ]);
        Storage::forgetDisk('public');

        $request = Request::create('https://erp.example.com/api/vehicles', 'GET');

        $this->assertSame(
            'https://erp.example.com/storage/vehicles/1/photo.webp',
            StorageAssetUrl::forRequest(
                $request,
                'public',
                'vehicles/1/photo.webp',
                followRequestOrigin: true,
            ),
        );
    }

    public function test_remote_storage_url_keeps_the_disk_generated_url_even_when_origin_override_is_enabled(): void
    {
        config(['filesystems.disks.vehicle_photos.driver' => 's3']);
        Storage::shouldReceive('disk')
            ->once()
            ->with('vehicle_photos')
            ->andReturn(new class
            {
                public function url(string $path): string
                {
                    return 'https://cdn.example.com/'.$path;
                }
            });

        $request = Request::create('http://192.168.0.40:8000/api/vehicles', 'GET');

        $this->assertSame(
            'https://cdn.example.com/vehicles/1/photo.webp',
            StorageAssetUrl::forRequest(
                $request,
                'vehicle_photos',
                'vehicles/1/photo.webp',
                followRequestOrigin: true,
            ),
        );
    }
}
