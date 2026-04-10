<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\Config;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Persistence\DeliveryStateStore;
use App\Integration\Queue\RabbitMqQueue;
use App\UseCase\IngestWebhookEvent;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBitrixRestClient;
use Tests\Support\FrozenClock;

class IngestWebhookEventTest extends TestCase
{
    public function testQueuesTargetTransitionOnlyOnce(): void
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

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $state->syncDealStage(2002, 'PROCESS', 'main');
        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                    default => throw new \RuntimeException('Неожиданный метод: ' . $method),
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        $payload = $this->webhookPayload(2002, '1736405807', [
            'access_token' => 'token-1',
        ]);

        $first = $useCase->handle($payload);
        self::assertSame('queued', $first['status']);
        self::assertArrayHasKey('event_key', $first);
        self::assertSame([
            'status' => 'duplicate',
            'event_key' => $first['event_key'],
            'message' => 'Событие с таким ключом уже встречалось',
        ], $useCase->handle($this->webhookPayload(2002, '1736405807', [
            'access_token' => 'token-2',
            'refresh_token' => 'refresh-2',
        ])));

        $ignored = $useCase->handle($this->webhookPayload(2002, '1736405808'));
        self::assertSame([
            'status' => 'ignored',
            'event_key' => $ignored['event_key'],
            'message' => 'Сделка не вошла в целевую стадию',
        ], $ignored);

        self::assertCount(1, $queue->published);
        self::assertSame($first['event_key'], $queue->published[0]['event_key']);
    }

    public function testDetectsStageChangeFromBitrixStateWithoutPreviousStage(): void
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

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params, array $calls): array {
                if ($method !== 'crm.deal.get') {
                    throw new \RuntimeException('Неожиданный метод: ' . $method);
                }

                $position = 0;

                foreach ($calls as $call) {
                    if (($call['method'] ?? '') === 'crm.deal.get') {
                        $position++;
                    }
                }

                return match ($position) {
                    1 => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'PROCESS',
                        'CATEGORY_ID' => 'main',
                    ],
                    default => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        self::assertSame([
            'status' => 'ignored',
            'message' => 'Событие вне настроенной стадии или категории',
        ], $useCase->handle($this->webhookPayload(2002, '1736405807')));

        self::assertSame([
            'status' => 'queued',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405808')),
        ], $useCase->handle($this->webhookPayload(2002, '1736405808')));

        self::assertSame([
            'status' => 'ignored',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405809')),
            'message' => 'Сделка не вошла в целевую стадию',
        ], $useCase->handle($this->webhookPayload(2002, '1736405809')));

        self::assertCount(1, $queue->published);
        self::assertSame($this->eventKeyForPayload($this->webhookPayload(2002, '1736405808')), $queue->published[0]['event_key']);
    }

    public function testUsesSimulatedCurrentStateForLocalEmulation(): void
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

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method): \Throwable {
                return new \RuntimeException('В режиме симуляции Bitrix вызываться не должен.');
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        self::assertSame([
            'status' => 'ignored',
            'message' => 'Событие вне настроенной стадии или категории',
        ], $useCase->handle($this->simulatePayload(2002, '1736405807', 'PROCESS', 'main')));

        self::assertSame([
            'status' => 'queued',
            'event_key' => $this->eventKeyForPayload($this->simulatePayload(2002, '1736405808', 'DELIVERY', 'main')),
        ], $useCase->handle($this->simulatePayload(2002, '1736405808', 'DELIVERY', 'main')));

        self::assertCount(1, $queue->published);
        self::assertSame($this->eventKeyForPayload($this->simulatePayload(2002, '1736405808', 'DELIVERY', 'main')), $queue->published[0]['event_key']);
    }

    public function testQueuesFirstTargetTransitionWithoutBaselineUsingStageHistory(): void
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

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                    'crm.stagehistory.list' => [
                        'items' => [
                            [
                                'ID' => '91',
                                'STAGE_ID' => 'DELIVERY',
                                'CATEGORY_ID' => 'main',
                            ],
                            [
                                'ID' => '90',
                                'STAGE_ID' => 'PROCESS',
                                'CATEGORY_ID' => 'main',
                            ],
                        ],
                    ],
                    default => throw new \RuntimeException('Неожиданный метод: ' . $method),
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        self::assertSame([
            'status' => 'queued',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405807')),
        ], $useCase->handle($this->webhookPayload(2002, '1736405807')));

        self::assertCount(1, $queue->published);
    }

    public function testReadsDealIdFromRealWebhookShapeAndChecksHistoryAfterDealGet(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('В локальном PHP недоступен драйвер pdo_sqlite.');
        }

        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $state = new DeliveryStateStore($pdo, $clock->now(...), 'DELIVERY', '0');
        $state->migrate();

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            return match ($method) {
                'crm.deal.get' => [
                    'ID' => (string) $params['id'],
                    'TITLE' => 'Новая сделка #1',
                    'STAGE_ID' => 'DELIVERY',
                    'CATEGORY_ID' => '0',
                    'CONTACT_ID' => '84',
                    'ASSIGNED_BY_ID' => '1',
                ],
                'crm.stagehistory.list' => [
                    'items' => [
                        [
                            'ID' => 35,
                            'TYPE_ID' => 1,
                            'OWNER_ID' => (int) $params['filter']['OWNER_ID'],
                            'CREATED_TIME' => '2024-04-25T14:59:11+00:00',
                            'CATEGORY_ID' => 0,
                            'STAGE_SEMANTIC_ID' => 'P',
                            'STAGE_ID' => 'DELIVERY',
                        ],
                        [
                            'ID' => 34,
                            'TYPE_ID' => 1,
                            'OWNER_ID' => (int) $params['filter']['OWNER_ID'],
                            'CREATED_TIME' => '2024-04-24T14:59:11+00:00',
                            'CATEGORY_ID' => 0,
                            'STAGE_SEMANTIC_ID' => 'P',
                            'STAGE_ID' => 'PREPARATION',
                        ],
                    ],
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $bitrix = new BitrixCrm($rest, new Config([
            'app_target_stage' => 'DELIVERY',
            'app_target_category' => '0',
            'bitrix_member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
            'bitrix_deal_entity_type_id' => 2,
        ]));
        $useCase = new IngestWebhookEvent(
            new Config([
                'app_target_stage' => 'DELIVERY',
                'app_target_category' => '0',
                'bitrix_member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
            ]),
            $bitrix,
            $state,
            $queue
        );

        $payload = [
            'event' => 'ONCRMDEALUPDATE',
            'event_handler_id' => '201',
            'data' => [
                'FIELDS' => [
                    'ID' => '759',
                ],
            ],
            'ts' => '1736405807',
            'auth' => [
                'access_token' => 'example-access-token',
                'expires_in' => '3600',
                'scope' => 'crm',
                'domain' => 'some-domain.bitrix24.com',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'status' => 'F',
                'client_endpoint' => 'https://some-domain.bitrix24.com/rest/',
                'member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
                'refresh_token' => 'example-refresh-token',
                'application_token' => 'example-application-token',
            ],
        ];

        $result = $useCase->handle($payload);

        self::assertSame('queued', $result['status']);
        self::assertSame(['crm.deal.get', 'crm.stagehistory.list'], array_column($rest->calls, 'method'));
        self::assertSame(759, $queue->published[0]['deal_id']);
        self::assertSame('DELIVERY', $queue->published[0]['stage']);
        self::assertSame('0', $queue->published[0]['category']);
    }

    public function testStopsOnForeignCategoryAfterDealGetWithoutCallingHistory(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('В локальном PHP недоступен драйвер pdo_sqlite.');
        }

        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $state = new DeliveryStateStore($pdo, $clock->now(...), 'DELIVERY', '0');
        $state->migrate();

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            return match ($method) {
                'crm.deal.get' => [
                    'ID' => (string) $params['id'],
                    'TITLE' => 'Новая сделка #1',
                    'TYPE_ID' => 'COMPLEX',
                    'STAGE_ID' => 'DELIVERY',
                    'CATEGORY_ID' => '7',
                    'CONTACT_ID' => '84',
                    'ASSIGNED_BY_ID' => '1',
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $bitrix = new BitrixCrm($rest, new Config([
            'app_target_stage' => 'DELIVERY',
            'app_target_category' => '0',
            'bitrix_member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
            'bitrix_deal_entity_type_id' => 2,
        ]));
        $useCase = new IngestWebhookEvent(
            new Config([
                'app_target_stage' => 'DELIVERY',
                'app_target_category' => '0',
                'bitrix_member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
            ]),
            $bitrix,
            $state,
            $queue
        );

        $result = $useCase->handle([
            'event' => 'ONCRMDEALUPDATE',
            'event_handler_id' => '201',
            'data' => [
                'FIELDS' => [
                    'ID' => '759',
                ],
            ],
            'ts' => '1736405807',
            'auth' => [
                'access_token' => 'example-access-token',
                'expires_in' => '3600',
                'scope' => 'crm',
                'domain' => 'some-domain.bitrix24.com',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'status' => 'F',
                'client_endpoint' => 'https://some-domain.bitrix24.com/rest/',
                'member_id' => 'a223c6b3710f85df22e9377d6c4f7553',
                'refresh_token' => 'example-refresh-token',
                'application_token' => 'example-application-token',
            ],
        ]);

        self::assertSame([
            'status' => 'ignored',
            'message' => 'Событие вне настроенной стадии или категории',
        ], $result);
        self::assertSame(['crm.deal.get'], array_column($rest->calls, 'method'));
        self::assertCount(0, $queue->published);
    }

    public function testDoesNotLoseTransitionWhenPublishFailsBeforeStageSnapshotIsUpdated(): void
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
        $state->syncDealStage(2002, 'PROCESS', 'main');

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];
            private int $publishCalls = 0;

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->publishCalls++;

                if ($this->publishCalls === 1) {
                    throw new \RuntimeException('Брокер недоступен');
                }

                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                    default => throw new \RuntimeException('Неожиданный метод: ' . $method),
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        try {
            $useCase->handle($this->webhookPayload(2002, '1736405807'));
            self::fail('Ожидалась ошибка публикации в очередь.');
        } catch (\RuntimeException $e) {
            self::assertSame('Брокер недоступен', $e->getMessage());
        }

        self::assertSame('PROCESS', $state->dealStateSnapshot(2002)['stage'] ?? null);

        self::assertSame([
            'status' => 'queued',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405808')),
        ], $useCase->handle($this->webhookPayload(2002, '1736405808')));

        self::assertCount(1, $queue->published);
        self::assertSame('DELIVERY', $state->dealStateSnapshot(2002)['stage'] ?? null);
    }

    public function testQueuesTransitionIntoTargetCategoryWhenSameStageExistsInAnotherCategory(): void
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
        $state->syncDealStage(2002, 'DELIVERY', 'secondary');

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                    default => throw new \RuntimeException('Неожиданный метод: ' . $method),
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        self::assertSame([
            'status' => 'queued',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405807')),
        ], $useCase->handle($this->webhookPayload(2002, '1736405807')));

        self::assertCount(1, $queue->published);
    }

    public function testIgnoresFirstTargetWebhookWhenHistoryCannotConfirmTransition(): void
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

        $queue = new class () extends RabbitMqQueue {
            public array $published = [];

            public function __construct()
            {
            }

            public function publish(array $message): void
            {
                $this->published[] = $message;
            }
        };

        $bitrix = new BitrixCrm(
            new FakeBitrixRestClient(function (string $method, array $params): array {
                return match ($method) {
                    'crm.deal.get' => [
                        'ID' => (string) $params['id'],
                        'STAGE_ID' => 'DELIVERY',
                        'CATEGORY_ID' => 'main',
                    ],
                    'crm.stagehistory.list' => [
                        'items' => [
                            [
                                'ID' => '91',
                                'STAGE_ID' => 'DELIVERY',
                                'CATEGORY_ID' => 'main',
                            ],
                        ],
                    ],
                    default => throw new \RuntimeException('Неожиданный метод: ' . $method),
                };
            }),
            $this->config()
        );
        $useCase = new IngestWebhookEvent($this->config(), $bitrix, $state, $queue);

        self::assertSame([
            'status' => 'ignored',
            'event_key' => $this->eventKeyForPayload($this->webhookPayload(2002, '1736405807')),
            'message' => 'Не удалось подтвердить переход сделки в целевую стадию',
        ], $useCase->handle($this->webhookPayload(2002, '1736405807')));

        self::assertCount(0, $queue->published);
    }

    private function config(): Config
    {
        return new Config([
            'app_target_stage' => 'DELIVERY',
            'app_target_category' => 'main',
            'bitrix_member_id' => 'demo-member',
        ]);
    }

    private function webhookPayload(int $dealId, string $ts, array $auth = []): array
    {
        return [
            'event' => 'ONCRMDEALUPDATE',
            'event_handler_id' => '201',
            'data' => [
                'FIELDS' => [
                    'ID' => (string) $dealId,
                ],
            ],
            'ts' => $ts,
            'auth' => array_merge([
                'access_token' => 'token',
                'expires_in' => '3600',
                'scope' => 'crm',
                'domain' => 'some-domain.bitrix24.com',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'status' => 'F',
                'client_endpoint' => 'https://some-domain.bitrix24.com/rest/',
                'member_id' => 'demo-member',
                'refresh_token' => 'refresh',
                'application_token' => 'app-token',
            ], $auth),
        ];
    }

    private function simulatePayload(int $dealId, string $ts, string $stage, string $category): array
    {
        $payload = $this->webhookPayload($dealId, $ts);
        $payload['simulate'] = true;
        $payload['simulate_current'] = [
            'stage' => $stage,
            'category' => $category,
        ];

        return $payload;
    }

    private function eventKeyForPayload(array $payload): string
    {
        if (isset($payload['event_id']) && $payload['event_id'] !== '') {
            return (string) $payload['event_id'];
        }

        if (isset($payload['auth']) && is_array($payload['auth'])) {
            $payload['auth'] = [
                'member_id' => (string) ($payload['auth']['member_id'] ?? ''),
                'domain' => (string) ($payload['auth']['domain'] ?? ''),
            ];
        }

        return sha1(json_encode($this->sortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursive($value);
            }
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        ksort($payload);

        return $payload;
    }
}
