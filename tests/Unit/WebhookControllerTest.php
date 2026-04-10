<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\JsonLogger;
use App\Presentation\Http\WebhookController;
use App\UseCase\IngestWebhookEvent;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

class WebhookControllerTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        http_response_code(200);
    }

    public function testRejectsTooLargePayloadBeforeReadingBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '999';

        $useCase = $this->getMockBuilder(IngestWebhookEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $useCase->expects(self::never())->method('handle');

        $controller = new WebhookController(
            $useCase,
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            32
        );

        ob_start();
        $controller->handle('/webhook/secret', 'secret');
        $body = (string) ob_get_clean();

        self::assertSame(413, http_response_code());
        self::assertSame('{"status":"payload_too_large"}', $body);
    }

    public function testRejectsInvalidJsonAsClientError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '9';

        $useCase = $this->getMockBuilder(IngestWebhookEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $useCase->expects(self::never())->method('handle');

        $logger = new class ((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)) extends JsonLogger {
            public array $records = [];

            public function warning(string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => 'warning',
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };

        $controller = new class (
            $useCase,
            $logger,
            64
        ) extends WebhookController {
            protected function rawInput(): string|false
            {
                return '{bad json';
            }
        };

        ob_start();
        $controller->handle('/webhook/secret', 'secret');
        $body = (string) ob_get_clean();

        self::assertSame(422, http_response_code());
        self::assertSame('{"status":"invalid","message":"Невалидный JSON"}', $body);
        self::assertSame('/webhook/{secret}', $logger->records[0]['context']['path'] ?? null);
    }
}
