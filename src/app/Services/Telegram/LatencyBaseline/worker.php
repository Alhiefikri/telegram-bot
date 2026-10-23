<?php

use App\Services\Telegram\LatencyBaseline\LatencyBaselineTransport;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
LatencyBaselineTransport::install();
exit($app->handleCommand(new ArgvInput));
