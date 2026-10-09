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

    public function test_only_owner_and_admin_roles_may_open_the_admin_dashboard(): void
    {
        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin')
                ->assertOk();
        }

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            auth()->logout();

            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin')
                ->assertForbidden();
        }
    }

    public function test_finance_and_support_roles_cannot_mutate_admin_surfaces(): void
    {
        Http::fake();
        Customer::factory()->create(['telegram_id' => 112]);

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            auth()->logout();
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->post(route('admin.products.store'), [
                    'name' => 'Blocked Product',
                    'price_amount' => 1000,
                    'description' => 'No access',
                ])
                ->assertForbidden();

            auth()->logout();

            $this->actingAs($user)
                ->post(route('admin.broadcast.store'), ['message' => 'Should not broadcast'])
                ->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_finance_and_support_roles_cannot_log_in_through_admin_login(): void
    {
        $finance = User::factory()->create([
            'email' => 'finance@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Finance,
        ]);
        $support = User::factory()->create([
            'email' => 'support@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Support,
        ]);

        foreach ([$finance, $support] as $user) {
            $this->post(route('admin.login.store'), [
                'email' => $user->email,
                'password' => 'correct-password',
            ])->assertSessionHasErrors(['email']);

            $this->assertGuest();
        }

        $admin = User::factory()->create([
            'email' => 'admin-login@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Admin,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

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
            'admin.storefront-placeholder.update' => [
                'uri' => 'admin/storefront-placeholder',
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
            'admin.manual-fulfillment-reviews.index' => [
                'uri' => 'admin/manual-fulfillment-reviews',
                'methods' => ['GET', 'HEAD'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.manual-fulfillment-reviews.show' => [
                'uri' => 'admin/manual-fulfillment-reviews/{review}',
                'methods' => ['GET', 'HEAD'],
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
            'admin.categories.update' => [
                'uri' => 'admin/categories/{category}',
                'methods' => ['PATCH'],
                'middleware' => ['web', 'admin.no-cache', 'admin.access'],
            ],
            'admin.categories.destroy' => [
                'uri' => 'admin/categories/{category}',
                'methods' => ['DELETE'],
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

    public function test_admin_login_throttle_blocks_the_sixth_failed_attempt_for_one_identity_and_ip(): void
    {
        $ip = '198.51.100.80';
        $email = 'throttle@example.test';

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post(route('admin.login.store'), [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => $email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email']);

        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            (string) collect($response->getSession()->get('errors')->get('email'))->first(),
        );
    }

    public function test_admin_login_throttle_blocks_identifier_rotation_at_the_global_ip_limit(): void
    {
        $ip = '198.51.100.81';

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post(route('admin.login.store'), [
                    'email' => "rotating-{$attempt}@example.test",
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => 'rotating-eleventh@example.test',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email']);

        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            (string) collect($response->getSession()->get('errors')->get('email'))->first(),
        );
    }

    public function test_successful_admin_login_clears_only_identity_throttle_state(): void
    {
        config()->set('auth.admin_login_throttle.identity_max_attempts', 2);
        config()->set('auth.admin_login_throttle.ip_max_attempts', 3);

        $ip = '198.51.100.82';
        $admin = User::factory()->create([
            'email' => 'clear-throttle@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email' => 'Email atau password admin tidak cocok.']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.store'), [
                'email' => 'after-clear@example.test',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['email']);

        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            (string) collect($response->getSession()->get('errors')->get('email'))->first(),
        );
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
