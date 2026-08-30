<?php

namespace Tests\Feature;

use App\Jobs\MaterializeProductWaitlist;
use App\Jobs\SendProductWaitlistConfirmation;
use App\Jobs\SendProductWaitlistNotification;
use App\Mail\ProductWaitlistConfirmationMail;
use App\Mail\ProductWaitlistRestockedMail;
use App\Models\Product;
use App\Models\ProductWaitlistEvent;
use App\Models\ProductWaitlistNotification;
use App\Models\ProductWaitlistSubscription;
use App\Models\User;
use App\Services\ProductWaitlistEmailHasher;
use App\Services\ProductWaitlistNotificationService;
use App\Services\ProductWaitlistSubscriptionService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ProductWaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_unavailable_public_products_render_the_waitlist_action(): void
    {
        $unavailable = Product::factory()->create(['name' => 'Habis Sekarang', 'stock' => 1, 'reserved_stock' => 1]);
        $available = Product::factory()->create(['name' => 'Masih Tersedia', 'stock' => 1, 'reserved_stock' => 0]);

        $this->get(route('storefront.products.show', $unavailable))
            ->assertOk()
            ->assertSee('id="product-waitlist-cta"', false)
            ->assertSee('Beri tahu saya saat tersedia')
            ->assertSee('id="waitlist"', false)
            ->assertSee('action="'.route('storefront.waitlist.store', $unavailable).'"', false);

        $this->get(route('storefront.products.show', $available))
            ->assertOk()
            ->assertDontSee('id="product-waitlist-cta"', false)
            ->assertDontSee('id="waitlist"', false)
            ->assertDontSee('Beri tahu saya saat tersedia');
    }

    public function test_submission_is_generic_deduplicated_and_email_is_encrypted(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $route = route('storefront.waitlist.store', $product);
        $notice = config('storefront.waitlist.notices.pending_confirmation');

        $this->post($route, ['email' => ' Subscriber@Example.TEST '])
            ->assertRedirect()
            ->assertSessionHas('storefront_waitlist_notice', $notice);
        $this->post($route, ['email' => 'subscriber@example.test'])
            ->assertRedirect()
            ->assertSessionHas('storefront_waitlist_notice', $notice);

        $subscription = ProductWaitlistSubscription::query()->sole();
        $this->assertSame(ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION, $subscription->status);
        $this->assertSame('subscriber@example.test', $subscription->email);
        $this->assertSame(1, DB::table('product_waitlist_subscriptions')->count());
        $this->assertStringNotContainsString(
            'subscriber@example.test',
            (string) DB::table('product_waitlist_subscriptions')->value('email'),
        );
        Queue::assertPushed(SendProductWaitlistConfirmation::class, 1);
    }

    public function test_invalid_email_and_per_email_product_ip_rate_limits_are_enforced(): void
    {
        Queue::fake();
        $invalidProduct = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        $this->post(route('storefront.waitlist.store', $invalidProduct), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, ProductWaitlistSubscription::query()->count());

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        config()->set('services.storefront.waitlist_ip_rate_limit_per_hour', 1);
        config()->set('services.storefront.waitlist_email_rate_limit_per_hour', 1);
        config()->set('services.storefront.waitlist_product_rate_limit_per_hour', 1);

        $this->post(route('storefront.waitlist.store', $product), ['email' => 'one@example.test'])
            ->assertRedirect();
        $this->post(route('storefront.waitlist.store', $product), ['email' => 'two@example.test'])
            ->assertTooManyRequests();
    }

    public function test_unconfirmed_subscriptions_never_receive_restock_notifications(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->post(route('storefront.waitlist.store', $product), ['email' => 'pending@example.test']);
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'pending waitlist',
            $admin,
            '203.0.113.23',
            ['pending-secret'],
        )['movement_id'];

        (new MaterializeProductWaitlist($movementId))->handle(app(ProductWaitlistNotificationService::class));

        $this->assertSame(0, ProductWaitlistNotification::query()->count());
        $this->assertSame(ProductWaitlistEvent::STATUS_COMPLETE, ProductWaitlistEvent::query()->sole()->status);
        $this->assertSame(
            ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION,
            ProductWaitlistSubscription::query()->sole()->status,
        );
    }

    public function test_confirmation_is_single_use_and_expiry_does_not_activate_subscription(): void
    {
        Queue::fake();
        Mail::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->post(route('storefront.waitlist.store', $product), ['email' => 'confirm@example.test']);
        $job = $this->confirmationJobFor((int) ProductWaitlistSubscription::query()->sole()->id);
        $confirmationService = app(ProductWaitlistSubscriptionService::class);
        $confirmation = ProductWaitlistSubscription::query()->sole();
        $confirmationUrl = $confirmationService->confirmationUrl($confirmation->id, $confirmation->confirmation_expires_at);

        $job->handle($confirmationService);
        Mail::assertSent(ProductWaitlistConfirmationMail::class);

        $this->get($confirmationUrl)
            ->assertOk()
            ->assertSee(config('storefront.waitlist.notices.success'));
        $this->assertSame(ProductWaitlistSubscription::STATUS_ACTIVE, ProductWaitlistSubscription::query()->sole()->status);

        $this->get($confirmationUrl)
            ->assertOk()
            ->assertSee(config('storefront.waitlist.notices.already_requested'));
        Mail::assertSentTimes(ProductWaitlistConfirmationMail::class, 1);

        $expiredProduct = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->post(route('storefront.waitlist.store', $expiredProduct), ['email' => 'expired@example.test']);
        $expiredId = (int) ProductWaitlistSubscription::query()->latest('id')->value('id');
        $this->confirmationJobFor($expiredId);
        $expiredSubscription = ProductWaitlistSubscription::query()->findOrFail($expiredId);
        $expiredUrl = $confirmationService->confirmationUrl($expiredId, $expiredSubscription->confirmation_expires_at);

        Carbon::setTestNow(now()->addHours(2));
        try {
            $this->get($expiredUrl)
                ->assertOk()
                ->assertSee(config('storefront.waitlist.notices.expired_link'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION,
            ProductWaitlistSubscription::query()->findOrFail($expiredId)->status,
        );
    }

    public function test_unsubscribe_is_signed_idempotent_and_blocks_future_delivery(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $subscription = $this->activeSubscription($product, 'unsubscribe@example.test');
        $url = app(ProductWaitlistSubscriptionService::class)->unsubscribeUrl($subscription->id);

        $this->get($url)
            ->assertOk()
            ->assertSee(config('storefront.waitlist.notices.unsubscribed'));
        $this->get($url)
            ->assertOk()
            ->assertSee(config('storefront.waitlist.notices.unsubscribed'));

        $this->assertSame(ProductWaitlistSubscription::STATUS_UNSUBSCRIBED, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->unsubscribed_at);
    }

    public function test_restock_event_materializes_once_sends_once_and_contains_no_private_data(): void
    {
        Queue::fake();
        Mail::fake();
        $product = Product::factory()->create([
            'name' => 'Safe Restock Product',
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $subscription = $this->activeSubscription($product, 'delivery@example.test');
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'waitlist restock',
            $admin,
            '203.0.113.20',
            ['private-credential-secret'],
        )['movement_id'];

        $materializer = new MaterializeProductWaitlist($movementId);
        $materializer->handle(app(ProductWaitlistNotificationService::class));
        $materializer->handle(app(ProductWaitlistNotificationService::class));
        $notification = ProductWaitlistNotification::query()->sole();
        $this->assertSame(ProductWaitlistEvent::STATUS_COMPLETE, ProductWaitlistEvent::query()->sole()->status);

        $job = $this->notificationJobFor((int) $notification->id);
        $job->handle(app(ProductWaitlistNotificationService::class));
        $job->handle(app(ProductWaitlistNotificationService::class));

        Mail::assertSent(ProductWaitlistRestockedMail::class, function (ProductWaitlistRestockedMail $mail) use ($product): bool {
            $rendered = $mail->render();

            return str_contains($rendered, 'Safe Restock Product')
                && str_contains($rendered, 'Stok tersedia: 1')
                && str_contains($rendered, route('storefront.products.show', ['product' => $product->id]))
                && str_contains($rendered, 'waitlist/unsubscribe')
                && ! str_contains($rendered, 'delivery@example.test')
                && ! str_contains($rendered, 'private-credential-secret')
                && ! str_contains($rendered, 'Recovery Key');
        });
        Mail::assertSentTimes(ProductWaitlistRestockedMail::class, 1);
        $this->assertSame(ProductWaitlistNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame(ProductWaitlistSubscription::STATUS_NOTIFIED, $subscription->fresh()->status);
        $this->assertStringNotContainsString('delivery@example.test', json_encode($subscription->fresh()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_restock_recipient_materialization_is_bounded_and_resumes_by_cursor(): void
    {
        Queue::fake();
        config()->set('services.storefront.waitlist_max_recipients_per_event', 1);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->activeSubscription($product, 'bounded-one@example.test');
        $this->activeSubscription($product, 'bounded-two@example.test');
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'bounded waitlist',
            $admin,
            '203.0.113.24',
            ['bounded-secret'],
        )['movement_id'];
        $service = app(ProductWaitlistNotificationService::class);

        $service->materialize($movementId);
        $this->assertSame(1, ProductWaitlistNotification::query()->count());
        $this->assertSame(ProductWaitlistEvent::STATUS_PENDING, ProductWaitlistEvent::query()->sole()->status);

        $service->materialize($movementId);
        $this->assertSame(2, ProductWaitlistNotification::query()->count());
        $this->assertSame(ProductWaitlistEvent::STATUS_COMPLETE, ProductWaitlistEvent::query()->sole()->status);
    }

    public function test_inactive_product_is_skipped_before_waitlist_send(): void
    {
        Queue::fake();
        Mail::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->activeSubscription($product, 'inactive@example.test');
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'waitlist inactive',
            $admin,
            '203.0.113.21',
            ['inactive-secret'],
        )['movement_id'];
        (new MaterializeProductWaitlist($movementId))->handle(app(ProductWaitlistNotificationService::class));
        $notification = ProductWaitlistNotification::query()->sole();
        $product->forceFill(['is_active' => false])->save();

        (new SendProductWaitlistNotification($notification->id))->handle(app(ProductWaitlistNotificationService::class));

        $this->assertSame(ProductWaitlistNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_transport_failure_is_redacted_and_marked_retryable(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->activeSubscription($product, 'failure@example.test');
        $admin = User::factory()->create();
        $movementId = app(StockAdjustmentService::class)->adjustWithAvailability(
            $product,
            1,
            'waitlist transport failure',
            $admin,
            '203.0.113.22',
            ['failure-secret'],
        )['movement_id'];
        (new MaterializeProductWaitlist($movementId))->handle(app(ProductWaitlistNotificationService::class));
        $notification = ProductWaitlistNotification::query()->sole();
        Mail::shouldReceive('to')
            ->once()
            ->with('failure@example.test')
            ->andThrow(new RuntimeException('SMTP leaked failure@example.test provider-secret'));

        try {
            (new SendProductWaitlistNotification($notification->id))->handle(app(ProductWaitlistNotificationService::class));
            $this->fail('Expected waitlist delivery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('failure@example.test', $exception->getMessage());
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
        }

        $failed = $notification->fresh();
        $this->assertSame(ProductWaitlistNotification::STATUS_FAILED, $failed->status);
        $this->assertSame('mail_delivery_failed', $failed->last_error);
        $this->assertStringNotContainsString('failure@example.test', json_encode($failed->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_same_email_isolated_per_product_without_session_or_account_merging(): void
    {
        Queue::fake();
        $first = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $second = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        $this->post(route('storefront.waitlist.store', $first), ['email' => 'shared@example.test']);
        $this->post(route('storefront.waitlist.store', $second), ['email' => ' SHARED@example.test ']);
        $this->post(route('storefront.waitlist.store', $first), ['email' => 'shared@example.test']);

        $subscriptions = ProductWaitlistSubscription::query()->orderBy('product_id')->get();
        $this->assertCount(2, $subscriptions);
        $this->assertSame([$first->id, $second->id], $subscriptions->pluck('product_id')->all());
        $this->assertSame(1, $subscriptions->pluck('email_hash')->unique()->count());
        $this->assertNull($subscriptions->first()->getAttribute('session_id'));
        Queue::assertPushed(SendProductWaitlistConfirmation::class, 2);
    }

    private function activeSubscription(Product $product, string $email): ProductWaitlistSubscription
    {
        $normalized = app(ProductWaitlistEmailHasher::class)->normalize($email);

        return ProductWaitlistSubscription::query()->create([
            'product_id' => $product->id,
            'email' => $normalized,
            'email_hash' => app(ProductWaitlistEmailHasher::class)->hash($normalized),
            'status' => ProductWaitlistSubscription::STATUS_ACTIVE,
            'confirmed_at' => now(),
        ]);
    }

    private function confirmationJobFor(int $subscriptionId): SendProductWaitlistConfirmation
    {
        $job = null;
        Queue::assertPushed(SendProductWaitlistConfirmation::class, function (SendProductWaitlistConfirmation $pushed) use (&$job, $subscriptionId): bool {
            if ((int) $pushed->subscriptionId !== $subscriptionId) {
                return false;
            }
            $job = $pushed;

            return true;
        });
        $this->assertInstanceOf(SendProductWaitlistConfirmation::class, $job);

        /** @var SendProductWaitlistConfirmation $job */
        return $job;
    }

    private function notificationJobFor(int $notificationId): SendProductWaitlistNotification
    {
        $job = null;
        Queue::assertPushed(SendProductWaitlistNotification::class, function (SendProductWaitlistNotification $pushed) use (&$job, $notificationId): bool {
            if ((int) $pushed->notificationId !== $notificationId) {
                return false;
            }
            $job = $pushed;

            return true;
        });
        $this->assertInstanceOf(SendProductWaitlistNotification::class, $job);

        /** @var SendProductWaitlistNotification $job */
        return $job;
    }
}
