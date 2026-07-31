<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_legacy_telegram_mini_app_dashboard_path_redirects_to_admin_dashboard(): void
    {
        $this->get('/telegram-mini-app')
            ->assertRedirect('/admin');
    }

    public function test_admin_dashboard_responses_are_not_cached_by_mini_app_webviews(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $dashboard = $this->actingAs($owner)
            ->get('/admin?tab=produk')
            ->assertOk();

        $this->assertStringContainsString('no-store', (string) $dashboard->headers->get('Cache-Control'));

        $legacyMiniApp = $this->get('/telegram-mini-app')
            ->assertRedirect('/admin');

        $this->assertStringContainsString('no-store', (string) $legacyMiniApp->headers->get('Cache-Control'));
    }

    public function test_admin_session_cookie_supports_telegram_mini_app_webviews(): void
    {
        $response = $this->get('/admin/login')
            ->assertOk();

        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie, 'Admin login response should set the Laravel session cookie.');
        $this->assertTrue($sessionCookie->isSecure(), 'Telegram Mini App WebViews require Secure cookies.');
        $this->assertSame('none', strtolower((string) $sessionCookie->getSameSite()), 'Telegram Mini App WebViews need SameSite=None so the session survives inside Telegram.');
        $this->assertTrue((bool) config('session.partitioned'), 'Partitioned cookies keep the admin session available in modern third-party WebView contexts.');
    }

    public function test_admin_route_contracts_keep_existing_paths_methods_names_and_middleware(): void
    {
        $expectedRoutes = [
            'telegram-mini-app.dashboard' => [
                'uri' => 'telegram-mini-app',
                'methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                'middleware' => ['web', 'admin.no-cache'],
            ],
            'admin.dashboard' => [
                'uri' => 'admin',
                'methods' => ['GET', 'HEAD'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.login' => [
                'uri' => 'admin/login',
                'methods' => ['GET', 'HEAD'],
                'middleware' => ['web', 'admin.no-cache'],
            ],
            'admin.login.store' => [
                'uri' => 'admin/login',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache'],
            ],
            'admin.logout' => [
                'uri' => 'admin/logout',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache'],
            ],
            'admin.payment-mode.update' => [
                'uri' => 'admin/payment-mode',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.store-channel.update' => [
                'uri' => 'admin/store-channel',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.store-channel.test' => [
                'uri' => 'admin/store-channel/test',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.broadcast.store' => [
                'uri' => 'admin/broadcast',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.orders.show' => [
                'uri' => 'admin/orders/{order}',
                'methods' => ['GET', 'HEAD'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.orders.sales-proof.retry' => [
                'uri' => 'admin/orders/{order}/sales-proof/retry',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.store' => [
                'uri' => 'admin/products',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.update' => [
                'uri' => 'admin/products/{product}',
                'methods' => ['PATCH'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.destroy' => [
                'uri' => 'admin/products/{product}',
                'methods' => ['DELETE'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.visibility.update' => [
                'uri' => 'admin/products/{product}/visibility',
                'methods' => ['PATCH'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.stock.store' => [
                'uri' => 'admin/products/{product}/stock',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.stock-items.update' => [
                'uri' => 'admin/products/{product}/stock-items/{stockItem}',
                'methods' => ['PATCH'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.products.stock-items.destroy' => [
                'uri' => 'admin/products/{product}/stock-items/{stockItem}',
                'methods' => ['DELETE'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.categories.store' => [
                'uri' => 'admin/categories',
                'methods' => ['POST'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
        ];

        foreach ($expectedRoutes as $name => $expected) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} route exists");
            $this->assertSame($expected['uri'], $route->uri(), "{$name} uri");
            $this->assertSame($expected['methods'], $route->methods(), "{$name} methods");
            $this->assertSame($expected['middleware'], $route->gatherMiddleware(), "{$name} middleware");
        }
    }

    public function test_admin_login_rejects_bad_credentials_and_logout_returns_to_login(): void
    {
        $admin = User::factory()->create([
            'email' => 'owner@example.test',
            'name' => 'adminuser',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email']);

        $this->post(route('admin.login.store'), [
            'email' => 'adminuser',
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);

        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_admin_login_validation_keeps_custom_messages(): void
    {
        $this->from(route('admin.login'))
            ->post(route('admin.login.store'), [
                'email' => '',
                'password' => '',
            ])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors([
                'email' => 'Login admin wajib diisi.',
                'password' => 'Password admin wajib diisi.',
            ]);
    }

    public function test_admin_allow_list_blocks_other_admin_accounts(): void
    {
        config(['auth.admin_allowed_logins' => 'adminuser,owner@example.test']);

        $allowed = User::factory()->create([
            'name' => 'adminuser',
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);
        $blocked = User::factory()->create([
            'email' => 'other-admin@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($blocked)
            ->get('/admin')
            ->assertForbidden();

        $this->post(route('admin.login.store'), [
            'email' => $blocked->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors(['email']);

        $this->post(route('admin.login.store'), [
            'email' => $allowed->name,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_user_without_admin_access_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => null]));

        $this->get('/admin')
            ->assertForbidden();

        $this->post(route('admin.products.store'), [
            'name' => 'Blocked',
            'price_amount' => 1000,
            'description' => 'No access',
        ])->assertForbidden();
    }

    public function test_user_without_admin_access_cannot_log_in_through_admin_login(): void
    {
        $user = User::factory()->create([
            'email' => 'buyer@example.test',
            'password' => Hash::make('correct-password'),
            'role' => null,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_support_admin_cannot_send_broadcasts(): void
    {
        Http::fake();
        Customer::factory()->create(['telegram_id' => 111]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Support]))
            ->post(route('admin.broadcast.store'), [
                'message' => 'Support should not broadcast',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_broadcast_validation_uses_broadcast_error_bag_and_broadcast_tab(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->from('/admin?tab=broadcast')
            ->post(route('admin.broadcast.store'), ['message' => ''])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'broadcast']))
            ->assertSessionHasErrors(['message'], null, 'broadcast');

        Http::assertNothingSent();
    }

    public function test_broadcast_tab_is_rendered_with_desktop_and_mobile_navigation_controls(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get('/admin?tab=broadcast')
            ->assertOk()
            ->assertSee('\\u0022activeTab\\u0022:\\u0022broadcast\\u0022', false)
            ->assertSee('id="admin-section-broadcast"', false)
            ->assertSee('id="broadcast-message"', false)
            ->assertSee('Status Siaran')
            ->assertSeeInOrder(['Navigasi desktop', 'Siaran'])
            ->assertSeeInOrder(['Navigasi dashboard', 'Siaran']);
    }

    public function test_production_csrf_middleware_rejects_admin_mutation_without_token(): void
    {
        $middleware = new class($this->app, app(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $session = app('session.store');
        $session->start();
        $request = Request::create(route('admin.products.store'), 'POST');
        $request->setLaravelSession($session);

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn () => response('ok'));
    }

    public function test_production_csrf_middleware_allows_admin_mutation_with_matching_token(): void
    {
        $middleware = new class($this->app, app(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $session = app('session.store');
        $session->start();
        $request = Request::create(route('admin.products.store'), 'POST', [
            '_token' => $session->token(),
        ]);
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
