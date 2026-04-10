<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integration\Persistence\DeliveryStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

class DeliveryStateStoreTest extends TestCase
{
    public function testMigrateAddsMissingColumnsForLegacySchema(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('В локальном PHP недоступен драйвер pdo_sqlite.');
        }

        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE incoming_events (
            event_key TEXT PRIMARY KEY,
            deal_id INTEGER NOT NULL,
            stage TEXT NOT NULL,
            category TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE task_locks (
            external_key TEXT PRIMARY KEY,
            deal_id INTEGER NOT NULL,
            event_key TEXT NOT NULL,
            status TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE deal_state (
            deal_id INTEGER PRIMARY KEY,
            stage TEXT NOT NULL,
            category TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $store = new DeliveryStateStore($pdo, $clock->now(...), 'DELIVERY', 'main');
        $store->migrate();
        self::assertSame([], $store->recentEvents());
        self::assertSame([], $store->taskLocks());
    }

    public function testReservesTransitionsTracksStepsAndAllowsRealReentry(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('В локальном PHP недоступен драйвер pdo_sqlite.');
        }

        $clock = new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $store = new DeliveryStateStore($pdo, $clock->now(...), 'DELIVERY', 'main');
        $store->migrate();

        self::assertSame('queued', $store->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']));
        self::assertSame('duplicate_event', $store->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']));
        self::assertSame('duplicate_transition', $store->reserveDeliveryEvent('evt-2', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-2']));

        $store->markEventPublishFailed('evt-1', 'Не удалось опубликовать событие в очередь');
        self::assertSame(0, (int) $store->event('evt-1')['retry_count']);
        self::assertSame('queued', $store->reserveDeliveryEvent('evt-1', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-1']));

        $traceId = $store->rememberTraceId('evt-1', 'trace-1');
        self::assertSame('trace-1', $traceId);
        self::assertSame('trace-1', $store->rememberTraceId('evt-1', 'trace-2'));

        $store->markDealSaved('evt-1');
        $store->markTimelineSaved('evt-1');
        self::assertNotNull($store->event('evt-1')['deal_saved_at']);
        self::assertNotNull($store->event('evt-1')['timeline_saved_at']);

        self::assertSame('pending', $store->reserveTask('risk-delivery:2002:evt-1', 2002, 'evt-1'));
        self::assertSame('pending', $store->reserveTask('risk-delivery:2002:evt-1', 2002, 'evt-1'));
        $store->markTaskCreated('risk-delivery:2002:evt-1', 77);
        self::assertSame('created', $store->reserveTask('risk-delivery:2002:evt-1', 2002, 'evt-1'));

        $store->markEventProcessed('evt-1');
        $store->syncDealStage(2002, 'DELIVERY', 'main');
        self::assertSame('processed', $store->event('evt-1')['status']);

        self::assertSame('already_in_stage', $store->reserveDeliveryEvent('evt-3', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-3']));
        $store->syncDealStage(2002, 'DELIVERY', 'secondary');
        self::assertSame('queued', $store->reserveDeliveryEvent('evt-3b', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-3b']));
        $store->markEventProcessed('evt-3b');
        $store->syncDealStage(2002, 'DELIVERY', 'main');

        $store->syncDealStage(2002, 'PROCESS', 'main');
        self::assertSame('queued', $store->reserveDeliveryEvent('evt-4', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-4']));
        self::assertSame(1, $store->markEventFailed('evt-4', 'Таймаут worker'));
        self::assertSame('queued', $store->reserveDeliveryEvent('evt-4', 2002, 'DELIVERY', 'main', ['event_id' => 'evt-4']));

        self::assertSame(['processed' => 2, 'queued' => 1], $store->eventCounts());
        self::assertCount(3, $store->recentEvents());
        self::assertCount(1, $store->taskLocks());
    }
}
