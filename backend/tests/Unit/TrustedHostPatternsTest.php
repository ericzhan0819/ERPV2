<?php

namespace Tests\Unit;

use App\Support\TrustedHostPatterns;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class TrustedHostPatternsTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_bootstrap_wires_app_url_and_additional_hosts_without_implicit_subdomains(): void
    {
        config([
            'app.url' => 'http://100.112.1.114:8000',
            'trustedhosts.additional_hosts' => [
                '192.168.0.40',
                'localhost',
                '127.0.0.1',
                '::1',
                '100.112.1.114',
            ],
        ]);

        $this->assertSame([
            '\A100\.112\.1\.114\z',
            '\A192\.168\.0\.40\z',
            '\Alocalhost\z',
            '\A127\.0\.0\.1\z',
            '\A\[\:\:1\]\z',
        ], $this->app->make(TrustHosts::class)->hosts());
    }

    public function test_explicit_patterns_allow_canonical_lan_tailscale_and_health_check_hosts(): void
    {
        Request::setTrustedHosts(TrustedHostPatterns::from(
            'http://100.112.1.114:8000',
            ['192.168.0.40', 'localhost', '127.0.0.1', '::1'],
        ));

        $this->assertSame('100.112.1.114', Request::create('http://100.112.1.114:8000/up')->getHost());
        $this->assertSame('192.168.0.40', Request::create('http://192.168.0.40:8000/up')->getHost());
        $this->assertSame('localhost', Request::create('http://localhost/up')->getHost());
        $this->assertSame('127.0.0.1', Request::create('http://127.0.0.1/up')->getHost());
        $this->assertSame('[::1]', Request::create('http://[::1]/up')->getHost());
    }

    public function test_explicit_patterns_reject_unlisted_hosts_and_subdomains(): void
    {
        Request::setTrustedHosts(TrustedHostPatterns::from(
            'https://api.erp.example.com',
            ['localhost'],
        ));

        $this->expectException(SuspiciousOperationException::class);
        $this->expectExceptionMessage('Untrusted Host "evil.api.erp.example.com"');

        Request::create('https://evil.api.erp.example.com/api/vehicles')->getHost();
    }
}
