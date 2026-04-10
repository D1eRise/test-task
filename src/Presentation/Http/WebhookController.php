<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\App\JsonLogger;
use App\UseCase\IngestWebhookEvent;

class WebhookController
{
    public function __construct(
        private readonly IngestWebhookEvent $useCase,
        private readonly JsonLogger $logger,
        private readonly int $maxWebhookBytes
    )
    {
    }

    public function handle(string $requestPath, string $webhookSecret): void
    {
        header('Content-Type: application/json');
        $logPath = $requestPath;

        if ($requestPath === '/webhook/' . $webhookSecret) {
            $logPath = '/webhook/{secret}';
        } elseif (str_starts_with($requestPath, '/webhook/')) {
            $logPath = '/webhook/{invalid}';
        }

        if ($requestPath === '/health') {
            echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'method_not_allowed'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        if ($requestPath !== '/webhook/' . $webhookSecret) {
            http_response_code(404);
            echo json_encode(['status' => 'not_found'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > $this->maxWebhookBytes) {
            http_response_code(413);
            echo json_encode(['status' => 'payload_too_large'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        $rawBody = $this->rawInput();

        if ($rawBody !== false && strlen($rawBody) > $this->maxWebhookBytes) {
            http_response_code(413);
            echo json_encode(['status' => 'payload_too_large'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        try {
             
            $payload = json_decode($rawBody === false ? '{}' : $rawBody, true, 512, JSON_THROW_ON_ERROR);
            $result = $this->useCase->handle($payload);

            if ($result['status'] === 'forbidden') {
                http_response_code(403);
            } elseif ($result['status'] === 'invalid') {
                http_response_code(422);
            } else {
                http_response_code(202);
            }

            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            $this->logger->warning('Webhook отклонён: невалидный JSON.', [
                'path' => $logPath,
            ]);
            http_response_code(422);
            echo json_encode([
                'status' => 'invalid',
                'message' => 'Невалидный JSON',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $exception) {
            $this->logger->error('Обработка webhook завершилась ошибкой.', [
                'path' => $logPath,
                'error' => $exception->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Внутренняя ошибка сервиса',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    protected function rawInput(): string|false
    {
        return file_get_contents('php://input');
    }
}
