<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\JsonLogger;
use App\Integration\Bitrix\BitrixApiException;
use App\Integration\Bitrix\BitrixRestClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSleeper;
use Tests\Support\FrozenClock;
use Tests\Support\StubHttpClient;

class BitrixRestClientTest extends TestCase
{
     
    private array $stateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->stateFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testRetriesQueryLimitExceededAndHonoursRateLimit(): void
    {
        $http = new StubHttpClient([
            $this->response(200, json_encode([
                'error' => 'QUERY_LIMIT_EXCEEDED',
                'error_description' => 'Bitrix сообщил QUERY_LIMIT_EXCEEDED',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(200, json_encode([
                'result' => ['ID' => 2002],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $sleeper = new FakeSleeper();
        $client = new BitrixRestClient(
            $http,
            $sleeper->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            1,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 25,
            $this->rateLimitFile()
        );

        $res = $client->call('crm.deal.get', ['id' => 2002]);

        self::assertSame(['ID' => 2002], $res);
        self::assertSame(2, count($http->requests));
        self::assertSame(375, $sleeper->delays[0] ?? null);
        self::assertSame(1000, $sleeper->delays[1] ?? null);
        self::assertStringEndsWith('/crm.deal.get.json', $http->requests[0]['url']);
    }

    public function testRetriesOnHttp503AndTimeoutThenSucceeds(): void
    {
        $http = new StubHttpClient([
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            new \RuntimeException('timeout'),
            $this->response(200, json_encode([
                'result' => ['ok' => true],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $sleeper = new FakeSleeper();
        $client = new BitrixRestClient(
            $http,
            $sleeper->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            0,
            4,
            300,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 10,
            $this->rateLimitFile()
        );

        $res = $client->call('crm.deal.get', ['id' => 2002]);

        self::assertSame(['ok' => true], $res);
        self::assertSame([310, 610], $sleeper->delays);
    }

    public function testRejectsNestedBatchLocally(): void
    {
        $client = new BitrixRestClient(
            new StubHttpClient([]),
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            2,
            5,
            350,
            3000,
            null,
            null,
            $this->rateLimitFile()
        );

        try {
            $client->batch([
                'one' => 'batch?cmd[0]=crm.deal.get?id=1',
            ]);
            self::fail('Ожидалось исключение InvalidArgumentException, но оно не было выброшено.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Вложенный Bitrix batch не поддерживается.', $e->getMessage());
        }
    }

    public function testStopsAfterRetryBudget(): void
    {
        $http = new StubHttpClient([
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $client = new BitrixRestClient(
            $http,
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            0,
            3,
            300,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 10,
            $this->rateLimitFile()
        );

        try {
            $client->call('crm.deal.get', ['id' => 2002]);
            self::fail('Ожидалось исключение BitrixApiException, но оно не было выброшено.');
        } catch (BitrixApiException $e) {
            self::assertTrue($e->retryable());
            self::assertTrue($e->rateLimited());
            self::assertSame(503, $e->httpStatus());
        }
    }

    public function testSharesRateLimitWindowAcrossInstances(): void
    {
        $stateFile = $this->rateLimitFile();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $clientA = new BitrixRestClient(
            new StubHttpClient([
                $this->response(200, json_encode(['result' => ['ok' => 'a']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]),
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger($clock->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            1,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 25,
            $stateFile
        );
        $sleeperB = new FakeSleeper();
        $clientB = new BitrixRestClient(
            new StubHttpClient([
                $this->response(200, json_encode(['result' => ['ok' => 'b']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]),
            $sleeperB->sleepMilliseconds(...),
            new JsonLogger($clock->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            1,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 25,
            $stateFile
        );

        self::assertSame(['ok' => 'a'], $clientA->call('crm.deal.get', ['id' => 1]));
        self::assertSame(['ok' => 'b'], $clientB->call('crm.deal.get', ['id' => 2]));
        self::assertSame([1000], $sleeperB->delays);
    }

    public function testUnwrapsStageHistoryItemsFromVerboseBitrixResponse(): void
    {
        $http = new StubHttpClient([
            $this->response(200, json_encode([
                'result' => [
                    'items' => [
                        [
                            'ID' => 35,
                            'TYPE_ID' => 1,
                            'OWNER_ID' => 21,
                            'CREATED_TIME' => '2024-04-25T14:59:11+00:00',
                            'CATEGORY_ID' => 0,
                            'STAGE_SEMANTIC_ID' => 'P',
                            'STAGE_ID' => 'NEW',
                        ],
                    ],
                ],
                'total' => 1,
                'time' => [
                    'start' => 1724106224.858572,
                    'finish' => 1724106225.344968,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        $client = new BitrixRestClient(
            $http,
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            0,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 10,
            $this->rateLimitFile()
        );

        self::assertSame([
            'items' => [
                [
                    'ID' => 35,
                    'TYPE_ID' => 1,
                    'OWNER_ID' => 21,
                    'CREATED_TIME' => '2024-04-25T14:59:11+00:00',
                    'CATEGORY_ID' => 0,
                    'STAGE_SEMANTIC_ID' => 'P',
                    'STAGE_ID' => 'NEW',
                ],
            ],
        ], $client->call('crm.stagehistory.list', ['filter' => ['OWNER_ID' => 21]]));
    }

    public function testUnwrapsVerboseDealGetResponse(): void
    {
        $http = new StubHttpClient([
            $this->response(200, json_encode([
                'result' => [
                    'ID' => '410',
                    'TITLE' => 'Новая сделка #1',
                    'TYPE_ID' => 'COMPLEX',
                    'STAGE_ID' => 'PREPARATION',
                    'CATEGORY_ID' => '0',
                    'CONTACT_ID' => '84',
                    'ASSIGNED_BY_ID' => '1',
                ],
                'time' => [
                    'start' => 1725020945.541275,
                    'finish' => 1725020946.179076,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        $client = new BitrixRestClient(
            $http,
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://example.bitrix24.test/rest/1/demo',
            0,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 10,
            $this->rateLimitFile()
        );

        self::assertSame([
            'ID' => '410',
            'TITLE' => 'Новая сделка #1',
            'TYPE_ID' => 'COMPLEX',
            'STAGE_ID' => 'PREPARATION',
            'CATEGORY_ID' => '0',
            'CONTACT_ID' => '84',
            'ASSIGNED_BY_ID' => '1',
        ], $client->call('crm.deal.get', ['id' => 410]));
    }

    public function testSendsApplicationAccessTokenForAppOnlyMethods(): void
    {
        $http = new StubHttpClient([
            $this->response(200, json_encode([
                'result' => [
                    ['event' => 'ONCRMDEALUPDATE', 'handler' => 'https://demo.test/webhook/secret'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        $client = new BitrixRestClient(
            $http,
            (new FakeSleeper())->sleepMilliseconds(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            'https://some-domain.bitrix24.com/rest',
            0,
            3,
            350,
            3000,
            static fn (): int => 1000,
            static fn (int $attempt): int => 10,
            $this->rateLimitFile(),
            'oauth-access-token'
        );

        self::assertTrue($client->usesApplicationAuth());
        self::assertSame([
            ['event' => 'ONCRMDEALUPDATE', 'handler' => 'https://demo.test/webhook/secret'],
        ], $client->call('event.get'));
        self::assertStringEndsWith('/event.get.json', $http->requests[0]['url']);
        self::assertStringContainsString('auth=oauth-access-token', (string) $http->requests[0]['body']);
    }

    private function rateLimitFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'bitrix-rate-');

        if ($file === false) {
            self::fail('Не удалось создать временный файл для теста лимитов Bitrix.');
        }

        $this->stateFiles[] = $file;

        return $file;
    }

    private function response(int $statusCode, string $body, array $headers = []): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
            'headers' => $headers,
        ];
    }
}
