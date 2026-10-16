<?php

namespace App\ApiDocs;

/**
 * Synthetic but valid JSON examples for every documented schema and request
 * body. They contain no real secret, credential, key or balance and are
 * published both in the documentation page and inside the OpenAPI document, so
 * a client generator or a language model receives a complete, parseable shape.
 */
final class CustomerApiSamples
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'CreateOrderRequest' => [
                'product_id' => '42',
                'quantity' => 2,
                'preference' => 'usd_first',
                'expected_totals' => ['IDR' => '14000', 'USD' => '2'],
            ],
            'BalanceEnvelope' => [
                'data' => [
                    'payment_preference' => 'idr_first',
                    'wallets' => [
                        ['currency' => 'IDR', 'available' => true, 'frozen' => false, 'amount' => '125000'],
                        ['currency' => 'USD', 'available' => false, 'frozen' => true, 'amount' => null],
                    ],
                ],
            ],
            'ProductEnvelope' => ['data' => $this->product()],
            'ProductPage' => [
                'data' => [$this->product()],
                'pagination' => ['next_cursor' => '42', 'has_more' => true],
            ],
            'OrderCreateEnvelope' => [
                'data' => [
                    'id' => '9a1f0c52-9d3f-4de0-9b7a-3ccf0f2c9d10',
                    'invoice_number' => 'INV-20261016-0007',
                    'origin' => 'api',
                    'status' => 'processing',
                    'payment_status' => 'paid',
                    'currency' => 'USD',
                    'amount' => '2',
                    'quantity' => 2,
                    'product' => ['id' => '42', 'name' => 'Example Product'],
                    'created_at' => '2026-10-16T09:12:34+07:00',
                    'request_id' => '0f0c1c3e-6c2f-4c1d-9d4e-9d3f0d5a6b7c',
                ],
                'idempotent_replay' => false,
            ],
            'OrderPage' => [
                'data' => [$this->order()],
                'pagination' => ['next_cursor' => null, 'has_more' => false],
            ],
            'OrderEnvelope' => ['data' => $this->order()],
            'DeliveryEnvelope' => [
                'data' => [
                    'id' => '9a1f0c52-9d3f-4de0-9b7a-3ccf0f2c9d10',
                    'state' => 'partial',
                    'progress' => [
                        'state' => 'partial',
                        'ordered_units' => 2,
                        'allocated_units' => 2,
                        'released_units' => 1,
                        'processing' => true,
                        'review_required' => false,
                    ],
                    'units' => [[
                        'id' => '101',
                        'product_id' => '42',
                        'number' => 1,
                        'format' => 'structured_account',
                        'text' => "email: buyer@example.com\npassword: contoh-sandi-bukan-nyata",
                        'email' => 'buyer@example.com',
                        'password' => 'contoh-sandi-bukan-nyata',
                        'access_method' => 'otp_mail_link',
                        'access_value' => 'https://mail.example.com/reader/example-token',
                        'reader_mail_link' => 'https://mail.example.com/reader/example-token',
                        'tutorial' => 'Change the password right after the first login.',
                    ]],
                    'usage_guides' => [['title' => 'Example activation guide', 'url' => 'https://store.example/panduan']],
                ],
            ],
            'ErrorEnvelope' => [
                'error' => [
                    'code' => 'price_changed',
                    'message' => 'Checkout prices changed; authorize fresh totals before purchasing.',
                    'request_id' => '0f0c1c3e-6c2f-4c1d-9d4e-9d3f0d5a6b7c',
                    'quotes' => [['currency' => 'IDR', 'total' => '15000'], ['currency' => 'USD', 'total' => '2']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        return [
            'id' => '42',
            'name' => 'Example Product',
            'plan_label' => '1 Month',
            'description' => 'Example store-authored description.',
            'description_ms' => null,
            'description_en' => null,
            'icon' => '◈',
            'custom_emoji_id' => null,
            'category' => ['id' => '3', 'name' => 'Example Category', 'slug' => 'example-category', 'description' => null],
            'family' => ['id' => '7', 'name' => 'Example Family', 'slug' => 'example-family', 'description' => null, 'icon' => '◇', 'custom_emoji_id' => null],
            'stock' => ['available' => 12, 'is_available' => true, 'quantity_available' => true],
            'quantity' => 2,
            'quotes' => [
                ['currency' => 'IDR', 'eligible' => true, 'regular_unit' => '15000', 'effective_unit' => '7000', 'regular_total' => '30000', 'total' => '14000'],
                ['currency' => 'USD', 'eligible' => true, 'regular_unit' => '1', 'effective_unit' => '1', 'regular_total' => '2', 'total' => '2'],
            ],
            'input_requirements' => [],
            'metadata' => [
                'product_type' => 'digital-account',
                'access_duration' => '30 days',
                'region' => null,
                'account_type' => 'private',
                'device_limit' => null,
                'included_items' => 'Account credentials and usage guide.',
                'limitations' => null,
                'fulfillment_expectation' => 'Automatic',
                'warranty_policy' => 'Warranty per the store refund policy.',
                'warranty_policy_ms' => null,
                'warranty_policy_en' => null,
                'support_policy' => null,
                'delivery_note' => null,
                'usage_guide_title' => 'Example activation guide',
                'usage_guide_url' => 'https://store.example/panduan',
            ],
            'informational' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(): array
    {
        return [
            'id' => '9a1f0c52-9d3f-4de0-9b7a-3ccf0f2c9d10',
            'invoice_number' => 'INV-20261016-0007',
            'origin' => 'api',
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency' => 'USD',
            'amount' => '2',
            'quantity' => 2,
            'items' => [['product_id' => '42', 'name' => 'Example Product', 'quantity' => 2]],
            'fulfillment' => [
                'state' => 'partial',
                'ordered_units' => 2,
                'allocated_units' => 2,
                'released_units' => 1,
                'processing' => true,
                'review_required' => false,
            ],
            'delivery' => ['available' => true, 'state' => 'partial'],
            'refund' => ['state' => 'none', 'currency' => 'USD', 'confirmed_amount' => null, 'pending_amount' => '0'],
            'created_at' => '2026-10-16T09:12:34+07:00',
        ];
    }
}
