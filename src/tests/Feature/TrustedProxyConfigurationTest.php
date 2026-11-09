<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
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
        Request::setTrustedProxies([], 0);
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

    public function test_cloudflare_chain_selects_the_visitor_not_the_edge_or_a_forged_prefix(): void
    {
        config(['app.trusted_proxies' => ['10.20.0.5', '173.245.48.0/20']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])
            ->getJson('https://store.example/trust-probe', [
                'X-Forwarded-For' => '192.0.2.99, 198.51.100.10, 173.245.48.7, 10.20.0.5',
                'CF-Connecting-IP' => '192.0.2.98',
                'Forwarded' => 'for=192.0.2.97',
            ])
            ->assertOk()->assertJsonPath('ip', '198.51.100.10');
    }

    public function test_direct_cloudflare_peer_can_restore_ipv6_visitors(): void
    {
        config(['app.trusted_proxies' => ['2400:cb00::/32']]);

        $this->withServerVariables(['REMOTE_ADDR' => '2400:cb00::1234'])
            ->getJson('https://store.example/trust-probe', [
                'X-Forwarded-For' => '192.0.2.99, 2001:db8::10, 2400:cb00::1234',
            ])
            ->assertOk()->assertJsonPath('ip', '2001:db8::10');
    }

    public function test_an_unverified_intermediate_stops_attribution_without_trusting_cloudflare_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])
            ->getJson('https://store.example/trust-probe', [
                'X-Forwarded-For' => '198.51.100.10, 173.245.48.7, 10.20.0.5',
                'CF-Connecting-IP' => '198.51.100.10',
            ])
            ->assertOk()->assertJsonPath('ip', '173.245.48.7');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->getJson('https://store.example/trust-probe', [
                'X-Forwarded-For' => '192.0.2.10, 173.245.48.7',
                'CF-Connecting-IP' => '192.0.2.10',
                'X-Real-IP' => '192.0.2.10',
                'Forwarded' => 'for=192.0.2.10',
            ])
            ->assertOk()->assertJsonPath('ip', '198.51.100.5');
    }

    public function test_shared_visitor_addresses_cannot_rotate_identity_with_forged_prefixes(): void
    {
        config(['app.trusted_proxies' => ['173.245.48.0/20']]);
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.7']);

        foreach (['192.0.2.10', '192.0.2.11'] as $spoofed) {
            $this->getJson('https://store.example/trust-probe', [
                'X-Forwarded-For' => $spoofed.', 198.51.100.10, 173.245.48.7',
            ])->assertOk()->assertJsonPath('ip', '198.51.100.10');
        }

        $this->getJson('https://store.example/trust-probe', [
            'X-Forwarded-For' => '198.51.100.11, 173.245.48.7',
        ])->assertOk()->assertJsonPath('ip', '198.51.100.11');
    }

    public function test_empty_configuration_does_not_inherit_framework_host_or_static_auto_trust(): void
    {
        config(['app.trusted_proxies' => [], 'app.trusted_hosts' => []]);
        TrustProxies::at('*');

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
                ->getJson('https://attacker.on-forge.com/trust-probe', [
                    'X-Forwarded-For' => '192.0.2.10',
                ])
                ->assertOk()->assertJsonPath('ip', '198.51.100.5');
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_dynamic_global_and_malformed_proxy_entries_cannot_enable_forwarding_trust(): void
    {
        config(['app.trusted_proxies' => [
            '*', '**', 'REMOTE_ADDR', 'PRIVATE_SUBNETS', 'private_ranges',
            '0.0.0.0/0', '::/0', '198.51.100.5/33', '2001:db8::/129',
            '198.51.100.5/-1', '198.51.100.5/24/1', 'not-an-ip',
        ]]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->getJson('https://store.example/trust-probe', ['X-Forwarded-For' => '192.0.2.10'])
            ->assertOk()->assertJsonPath('ip', '198.51.100.5');
    }

    public function test_configured_hosts_reject_forged_hosts_and_preserve_the_canonical_alias(): void
    {
        $this->getJson('https://attacker.example/trust-probe')->assertStatus(400);
        $this->get('https://www.store.example/trust-probe')->assertRedirect('https://store.example/trust-probe');
        $this->getJson('https://store.example/trust-probe')->assertOk();
    }
}
