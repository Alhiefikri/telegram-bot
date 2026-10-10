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
    $http->fake([]);
    $http->preventStrayRequests();
});

$app->handleRequest(Request::capture());
