<?php

declare(strict_types=1);









$app = require __DIR__ . '/../config/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$app['stateStore']->migrate();
$app['webhookController']->handle((string) $path, $app['config']->webhookSecret);
