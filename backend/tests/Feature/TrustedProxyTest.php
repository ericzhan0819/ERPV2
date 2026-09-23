<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_provider_explicitly_binds_trusted_proxies_and_headers(): void
    {
        $reflection = new ReflectionClass(TrustProxies::class);

        $trustedProxies = $reflection->getProperty('alwaysTrustProxies')->getValue();

        $this->assertIsArray($trustedProxies);
        $this->assertNotEmpty($trustedProxies);
        $this->assertNotContains('*', $trustedProxies);
        $this->assertSame(config('trustedproxy.proxies', []), $trustedProxies);
        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
            $reflection->getProperty('alwaysTrustHeaders')->getValue(),
        );
    }

    public function test_loopback_reverse_proxy_can_forward_https_while_forwarded_host_is_ignored(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.example.com',
            ])
            ->getJson('http://api.erp.example.com/api/public/vehicles')
            ->assertOk();

        $response->assertJsonPath(
            'links.first',
            'https://api.erp.example.com/api/public/vehicles?page=1',
        );
    }

    public function test_untrusted_client_cannot_spoof_forwarded_https_scheme(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('http://api.erp.example.com/api/public/vehicles')
            ->assertOk();

        $response->assertJsonPath(
            'links.first',
            'http://api.erp.example.com/api/public/vehicles?page=1',
        );
    }
}
