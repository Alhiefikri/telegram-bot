<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

echo json_encode([
    'queue_depth' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
    'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
    'telegram_updates' => Schema::hasTable('telegram_updates') ? DB::table('telegram_updates')->count() : null,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
