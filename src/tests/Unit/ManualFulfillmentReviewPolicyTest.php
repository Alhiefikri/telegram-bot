<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\ManualFulfillmentReview;
use App\Models\User;
use App\Policies\ManualFulfillmentReviewPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManualFulfillmentReviewPolicyTest extends TestCase
{
    #[DataProvider('credentialReviewCases')]
    public function test_review_reason_preserves_existing_flows_and_owner_only_order_entry(string $reason, bool $accepts, bool $admin): void
    {
        $review = new ManualFulfillmentReview(['reason' => $reason]);
        $policy = new ManualFulfillmentReviewPolicy;
        $owner = (new User)->forceFill(['role' => UserRole::Owner]);
        $operator = (new User)->forceFill(['role' => UserRole::Admin]);
        $finance = (new User)->forceFill(['role' => UserRole::Finance]);

        self::assertSame($accepts, $review->acceptsLocalCredentials());
        self::assertSame($admin, $policy->provideCredentials($operator, $review));
        self::assertTrue($policy->provideCredentials($owner, $review));
        self::assertFalse($policy->provideCredentials($finance, $review));
    }

    public static function credentialReviewCases(): array
    {
        return [
            'order-specific accounts' => [ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING, true, false],
            'explicit unlimited mode' => [ManualFulfillmentReview::REASON_MANUAL_UNLIMITED, true, true],
            'duplicate email' => [ManualFulfillmentReview::REASON_UNIQUE_EMAIL_UNAVAILABLE, true, true],
            'supplier review is not a local entry reason' => ['supplier_fulfillment', false, true],
        ];
    }
}
