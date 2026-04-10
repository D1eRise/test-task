<?php

declare(strict_types=1);

namespace App\Integration\Courier;

class QuoteMockController
{
    public function __construct(private readonly QuoteCalculator $calculator)
    {
    }

    public function handle(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(
                ['error' => 'method_not_allowed'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return;
        }

        $rawBody = file_get_contents('php://input');

        try {
             
            $payload = json_decode($rawBody === false ? '{}' : $rawBody, true, 512, JSON_THROW_ON_ERROR);
            echo json_encode(
                $this->calculator->calculate($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\Throwable $exception) {
            http_response_code(422);
            echo json_encode(
                [
                    'error' => 'invalid_request',
                    'message' => $exception->getMessage(),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    }
}
