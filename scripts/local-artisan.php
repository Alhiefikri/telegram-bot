<?php

require __DIR__.'/../src/vendor/autoload.php';

$root = Dotenv\Dotenv::parse(file_get_contents(__DIR__.'/../.env'));

foreach ($root as $name => $value) {
    if (! str_starts_with($name, 'MAIL_') && ! in_array($name, ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'], true)) {
        continue;
    }

    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

putenv('APP_ENV=supplier-local');
$_ENV['APP_ENV'] = 'supplier-local';
$_SERVER['APP_ENV'] = 'supplier-local';

pcntl_exec(PHP_BINARY, [__DIR__.'/../src/artisan', ...array_slice($argv, 1)], getenv());

throw new RuntimeException('Unable to start the local Artisan process.');
