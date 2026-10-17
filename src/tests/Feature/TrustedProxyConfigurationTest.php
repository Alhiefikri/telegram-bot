<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Resolve the HTTP middleware from config, with no runtime trust ENV.
        config([
            'app.url' => 'https://store.example',
            'app.trusted_proxies' => ['10.20.0.0/16'],
            'app.trusted_hosts' => ['^store\\.example$'],
        ]);
        $this->app['env'] = 'production';
        Route::get('/trust-probe', fn (Request $request) => response()->json(['ip' => $request->ip()]));
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_empty_trust_lists_preserve_direct_client_identity_without_host_restrictions(): void
    {
        config(['app.trusted_proxies' => [], 'app.trusted_hosts' => []]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->getJson('https://other.example/trust-probe', ['X-Forwarded-For' => '192.0.2.11'])
            ->assertOk()->assertJsonPath('ip', '198.51.100.5');
    }

    public function test_only_configured_proxies_can_select_the_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])
            ->getJson('https://store.example/trust-probe', ['X-Forwarded-For' => '192.0.2.10'])
            ->assertOk()->assertJsonPath('ip', '192.0.2.10');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->getJson('https://store.example/trust-probe', ['X-Forwarded-For' => '192.0.2.11'])
            ->assertOk()->assertJsonPath('ip', '198.51.100.5');
    }

    public function test_configured_hosts_reject_forged_hosts_and_preserve_the_canonical_alias(): void
    {
        $this->getJson('https://attacker.example/trust-probe')->assertStatus(400);
        $this->get('https://www.store.example/trust-probe')->assertRedirect('https://store.example/trust-probe');
        $this->getJson('https://store.example/trust-probe')->assertOk();
    }
}
