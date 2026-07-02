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
use Tests\TestCase;

class AdminDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_login_rejects_bad_credentials_and_logout_returns_to_login(): void
    {
        $admin = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email']);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);

        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
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
