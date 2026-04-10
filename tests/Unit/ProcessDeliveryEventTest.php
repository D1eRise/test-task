<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\Config;
use App\App\JsonLogger;
use App\Domain\Delivery\RiskScoreCalculator;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Courier\CourierQuoteClient;
use App\Integration\Persistence\DeliveryStateStore;
use App\UseCase\ProcessDeliveryEvent;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBitrixRestClient;
use Tests\Support\FakeSleeper;
use Tests\Support\FrozenClock;
use Tests\Support\StubHttpClient;

class ProcessDeliveryEventTest extends TestCase
{
    public function testProcessesEventAndCreatesTaskOnce(): void
    {
        $state = $this->state();
        $state->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']);

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => 2002,
                        'TITLE' => 'Almaty Large Order',
                        'ASSIGNED_BY_ID' => 502,
                        'CREATED_BY_ID' => 501,
                        'CONTACT_ID' => 3002,
                        'UF_CRM_CITY_CODE_TO' => 'KZ-ALA-01',
                        'UF_CRM_WEIGHT_KG' => '13.5',
                        'UF_CRM_SLA_DUE_AT' => '2026-04-05T18:00:00+03:00',
                    ],
                    'crm.deal.contact.items.get' => [
                        ['CONTACT_ID' => 3002, 'SORT' => 100, 'ROLE_ID' => 0, 'IS_PRIMARY' => 'Y'],
                    ],
                    'crm.contact.get' => [
                        'ID' => 3002,
                        'NAME' => 'Aida',
                        'LAST_NAME' => 'S.',
                        'PHONE' => [['VALUE' => '+77010000002']],
                        'EMAIL' => [],
                    ],
                    'crm.deal.productrows.get' => [
                        ['ID' => 1, 'PRODUCT_NAME' => 'Item A', 'PRICE' => 100000, 'QUANTITY' => 1],
                        ['ID' => 2, 'PRODUCT_NAME' => 'Item B', 'PRICE' => 29000, 'QUANTITY' => 3],
                    ],
                    'crm.deal.update' => ['result' => true],
                    'crm.timeline.logmessage.list' => [],
                    'crm.timeline.logmessage.add' => ['logMessage' => ['id' => 10]],
                    'tasks.task.list' => [],
                    'tasks.task.add' => ['task' => ['id' => 77]],
                    default => throw new \RuntimeException('Неожиданный метод Bitrix: ' . $method),
                };
            }),
            $this->config()
        );

        $worker = new ProcessDeliveryEvent(
            (new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            $state,
            $bitrix,
            new CourierQuoteClient(
                new StubHttpClient([
                    $this->response(200, json_encode([
                        'zone' => 'Z3',
                        'eta_days' => 7,
                        'base_eta_days' => 6,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ]),
                new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
                (new FakeSleeper())->sleepMilliseconds(...),
                'http://courier-mock.test/v1/quote',
                100
            ),
            new RiskScoreCalculator(),
            false,
            0,
            0
        );

        $worker->handle([
            'event_key' => 'evt-1',
            'deal_id' => 2002,
            'stage' => 'DELIVERY',
            'category' => 'main',
            'payload' => [],
        ]);

        $event = $state->event('evt-1');
        self::assertSame('processed', $event['status']);
        self::assertNotNull($event['deal_saved_at']);
        self::assertNotNull($event['timeline_saved_at']);
        self::assertCount(1, $state->taskLocks());
        self::assertSame('created', $state->taskLocks()[0]['status']);
    }

    public function testRequeuesFailuresAndDoesNotMarkStepsAsDone(): void
    {
        $state = $this->state();
        $state->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']);

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => 2002,
                        'TITLE' => 'Almaty Large Order',
                        'ASSIGNED_BY_ID' => 502,
                        'CREATED_BY_ID' => 501,
                        'CONTACT_ID' => 3002,
                        'UF_CRM_CITY_CODE_TO' => 'KZ-ALA-01',
                        'UF_CRM_WEIGHT_KG' => '13.5',
                        'UF_CRM_SLA_DUE_AT' => '2026-04-05T18:00:00+03:00',
                    ],
                    'crm.deal.contact.items.get' => [
                        ['CONTACT_ID' => 3002, 'SORT' => 100, 'ROLE_ID' => 0, 'IS_PRIMARY' => 'Y'],
                    ],
                    'crm.contact.get' => [
                        'ID' => 3002,
                        'PHONE' => [['VALUE' => '+77010000002']],
                        'EMAIL' => [],
                    ],
                    'crm.deal.productrows.get' => [
                        ['ID' => 1, 'PRODUCT_NAME' => 'Item A', 'PRICE' => 100000, 'QUANTITY' => 1],
                        ['ID' => 2, 'PRODUCT_NAME' => 'Item B', 'PRICE' => 29000, 'QUANTITY' => 3],
                    ],
                    default => throw new \RuntimeException('Неожиданный метод Bitrix: ' . $method),
                };
            }),
            $this->config()
        );

        $courierResponses = [
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
            new \RuntimeException('таймаут'),
        ];

        $worker = new ProcessDeliveryEvent(
            (new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            $state,
            $bitrix,
            new CourierQuoteClient(
                new StubHttpClient($courierResponses),
                new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
                (new FakeSleeper())->sleepMilliseconds(...),
                'http://courier-mock.test/v1/quote',
                100
            ),
            new RiskScoreCalculator(),
            false,
            0,
            0
        );

        $msg = [
            'event_key' => 'evt-1',
            'deal_id' => 2002,
            'stage' => 'DELIVERY',
            'category' => 'main',
            'payload' => [],
        ];

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $worker->handle($msg);
                self::fail('Ожидалась повторяемая ошибка worker, но исключение не было выброшено.');
            } catch (\RuntimeException) {
                $event = $state->event('evt-1');
                self::assertSame('failed', $event['status']);
                self::assertSame($attempt, (int) $event['retry_count']);
                self::assertNull($event['deal_saved_at']);
                self::assertNull($event['timeline_saved_at']);
            }
        }

        $worker->handle($msg);

        $event = $state->event('evt-1');
        self::assertSame(5, (int) $event['retry_count']);
        self::assertNull($event['deal_saved_at']);
        self::assertNull($event['timeline_saved_at']);
        self::assertCount(0, $state->taskLocks());
    }

    public function testDryRunLeavesQueuedEventUntouched(): void
    {
        $state = $this->state();
        $state->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']);

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => 2002,
                        'TITLE' => 'Almaty Large Order',
                        'ASSIGNED_BY_ID' => 502,
                        'CREATED_BY_ID' => 501,
                        'CONTACT_ID' => 3002,
                        'UF_CRM_CITY_CODE_TO' => 'KZ-ALA-01',
                        'UF_CRM_WEIGHT_KG' => '13.5',
                        'UF_CRM_SLA_DUE_AT' => '2026-04-05T18:00:00+03:00',
                    ],
                    'crm.deal.contact.items.get' => [
                        ['CONTACT_ID' => 3002, 'SORT' => 100, 'ROLE_ID' => 0, 'IS_PRIMARY' => 'Y'],
                    ],
                    'crm.contact.get' => [
                        'ID' => 3002,
                        'PHONE' => [['VALUE' => '+77010000002']],
                        'EMAIL' => [],
                    ],
                    'crm.deal.productrows.get' => [
                        ['ID' => 1, 'PRODUCT_NAME' => 'Item A', 'PRICE' => 100000, 'QUANTITY' => 1],
                        ['ID' => 2, 'PRODUCT_NAME' => 'Item B', 'PRICE' => 29000, 'QUANTITY' => 3],
                    ],
                    default => throw new \RuntimeException('Неожиданный метод Bitrix: ' . $method),
                };
            }),
            $this->config()
        );

        $worker = new ProcessDeliveryEvent(
            (new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...),
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            $state,
            $bitrix,
            new CourierQuoteClient(
                new StubHttpClient([
                    $this->response(200, json_encode([
                        'zone' => 'Z3',
                        'eta_days' => 7,
                        'base_eta_days' => 6,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ]),
                new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
                (new FakeSleeper())->sleepMilliseconds(...),
                'http://courier-mock.test/v1/quote',
                100
            ),
            new RiskScoreCalculator(),
            false,
            0,
            0
        );

        $worker->handle([
            'event_key' => 'evt-1',
            'deal_id' => 2002,
            'stage' => 'DELIVERY',
            'category' => 'main',
            'payload' => [],
        ], true);

        self::assertSame('queued', $state->event('evt-1')['status']);
        self::assertNull($state->event('evt-1')['deal_saved_at']);
        self::assertNull($state->event('evt-1')['timeline_saved_at']);
        self::assertCount(0, $state->taskLocks());
    }

    private function state(): DeliveryStateStore
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('В локальном PHP недоступен драйвер pdo_sqlite.');
        }

        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $state = new DeliveryStateStore($pdo, $clock->now(...), 'DELIVERY', 'main');
        $state->migrate();

        return $state;
    }

    private function config(): Config
    {
        return new Config([
            'bitrix_deal_entity_type_id' => 2,
            'bitrix_field_city_code_to' => 'UF_CRM_CITY_CODE_TO',
            'bitrix_field_weight_kg' => 'UF_CRM_WEIGHT_KG',
            'bitrix_field_sla_due_at' => 'UF_CRM_SLA_DUE_AT',
            'bitrix_field_risk_score' => 'UF_CRM_RISK_SCORE',
            'bitrix_field_eta_days' => 'UF_CRM_ETA_DAYS',
            'bitrix_field_delivery_zone' => 'UF_CRM_DELIVERY_ZONE',
            'bitrix_field_diagnostic_hash' => 'UF_CRM_DIAGNOSTIC_HASH',
            'bitrix_field_raw_quote_json' => 'UF_CRM_RAW_QUOTE_JSON',
        ]);
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
