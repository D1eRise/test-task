<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$controller = new \App\Integration\Courier\QuoteMockController(
    \App\Integration\Courier\QuoteCalculator::fromFile(
        dirname(__DIR__, 2) . '/resources/fixtures/zones.php'
    )
);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path !== '/v1/quote') {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return;
}

$controller->handle();
