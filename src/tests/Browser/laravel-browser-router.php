<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

$publicDirectory = realpath(__DIR__.'/../../public');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestedFile = $publicDirectory === false || ! is_string($requestPath)
    ? false
    : realpath($publicDirectory.$requestPath);

if (
    $requestedFile !== false
    && $publicDirectory !== false
    && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
Facade::setFacadeApplication($app);

$app->booted(function () use ($app): void {
    $http = $app->make(Factory::class);
    $http->fake([
        'https://klikqris.com/api/sandbox/qris/history*' => Factory::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [
                    [
                        'order_id' => 'KLIK-BROWSER-MATCH',
                        'created_at' => '2026-08-14 10:15:00',
                        'total_amount' => '125000',
                        'status' => 'success',
                        'type' => 'qris',
                        'keterangan' => 'Browser reporting match',
                        'via' => 'mobile',
                    ],
                    [
                        'order_id' => 'KLIK-BROWSER-UNKNOWN',
                        'created_at' => '2026-08-14 10:16:00',
                        'total_amount' => '99000',
                        'status' => 'pending',
                        'type' => 'qris',
                        'keterangan' => 'Browser reporting mismatch',
                        'via' => 'api',
                    ],
                ],
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
                'first_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=2',
                'next_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=2',
                'prev_page_url' => null,
            ],
        ], 200),
        'https://klikqris.com/api/sandbox/withdraw/orders*' => Factory::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [[
                    'order_id' => 'KLIK-BROWSER-WITHDRAW',
                    'original_amount' => '125000',
                    'amount_paid_uniq' => '125016',
                    'mdr' => '875',
                    'net_amount_settled' => '124141',
                    'withdrawal_status' => 'success',
                    'transaction_paid_at' => '2026-08-14 10:15:00',
                    'withdrawal_requested_at' => '2026-08-14 10:20:00',
                    'withdrawal_processed_at' => '2026-08-14 10:25:00',
                    'withdrawal_code' => 'WD-BROWSER-1',
                    'withdrawal_proof_url' => 'https://klikqris.com/proofs/WD-BROWSER-1.png',
                ]],
                'per_page' => 1,
                'total' => 1,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/withdraw/orders?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/withdraw/orders?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200),
    ]);
    $http->preventStrayRequests();
});

$app->handleRequest(Request::capture());
