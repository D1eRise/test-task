<?php

declare(strict_types=1);

namespace App\Integration\Persistence;

class DeliveryStateStore
{
    private const SQLITE_BUSY_RETRIES = 5;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly \Closure $now,
        private readonly string $targetStage,
        private readonly string $targetCategory
    ) {
    }

    public function migrate(): void
    {
        $sql = [
            'CREATE TABLE IF NOT EXISTS deal_state (
                deal_id INTEGER PRIMARY KEY,
                stage TEXT NOT NULL,
                category TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS incoming_events (
                event_key TEXT PRIMARY KEY,
                deal_id INTEGER NOT NULL,
                stage TEXT NOT NULL,
                category TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL,
                trace_id TEXT DEFAULT NULL,
                deal_saved_at TEXT DEFAULT NULL,
                timeline_saved_at TEXT DEFAULT NULL,
                bp_started_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                queued_at TEXT DEFAULT NULL,
                processed_at TEXT DEFAULT NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL
            )',
            'CREATE TABLE IF NOT EXISTS task_locks (
                external_key TEXT PRIMARY KEY,
                deal_id INTEGER NOT NULL,
                event_key TEXT NOT NULL,
                status TEXT NOT NULL,
                bitrix_task_id INTEGER DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_incoming_events_status ON incoming_events (status)',
            'CREATE INDEX IF NOT EXISTS idx_task_locks_status ON task_locks (status)',
        ];

        foreach ($sql as $query) {
            $this->pdo->exec($query);
        }

        $this->ensureColumns('incoming_events', [
            'trace_id' => 'TEXT DEFAULT NULL',
            'deal_saved_at' => 'TEXT DEFAULT NULL',
            'timeline_saved_at' => 'TEXT DEFAULT NULL',
            'bp_started_at' => 'TEXT DEFAULT NULL',
            'queued_at' => 'TEXT DEFAULT NULL',
            'processed_at' => 'TEXT DEFAULT NULL',
            'retry_count' => 'INTEGER NOT NULL DEFAULT 0',
            'last_error' => 'TEXT DEFAULT NULL',
        ]);

        $this->ensureColumns('task_locks', [
            'bitrix_task_id' => 'INTEGER DEFAULT NULL',
            'created_at' => 'TEXT DEFAULT NULL',
            'updated_at' => 'TEXT DEFAULT NULL',
        ]);
    }

    public function reset(): void
    {
        $this->withWriteRetry(function (): void {
            foreach (['task_locks', 'incoming_events', 'deal_state'] as $table) {
                $this->pdo->exec('DELETE FROM ' . $table);
            }
        });
    }

    public function reserveDeliveryEvent(
        string $eventKey,
        int $dealId,
        string $stage,
        string $category,
        array $payload
    ): string {
        return $this->withWriteRetry(function () use ($eventKey, $dealId, $stage, $category, $payload): string {
            $this->pdo->beginTransaction();

            try {
                $existing = $this->event($eventKey);

                if ($existing !== null) {
                    if (
                        (string) $existing['status'] === 'failed'
                        && (int) $existing['deal_id'] === $dealId
                        && (string) $existing['stage'] === $stage
                        && (string) $existing['category'] === $category
                    ) {
                        $hasQueued = $this->hasQueuedTransition($dealId, $stage, $category, $eventKey);

                        if ($hasQueued) {
                            $this->pdo->commit();

                            return 'duplicate_transition';
                        }

                        $sql = $this->pdo->prepare(
                            'UPDATE incoming_events
                             SET deal_id = :deal_id,
                                 stage = :stage,
                                 category = :category,
                                 payload_json = :payload_json,
                                 status = :status,
                                 queued_at = :queued_at,
                                 processed_at = NULL,
                                 last_error = NULL
                             WHERE event_key = :event_key'
                        );
                        $sql->execute([
                            'event_key' => $eventKey,
                            'deal_id' => $dealId,
                            'stage' => $stage,
                            'category' => $category,
                            'payload_json' => json_encode(
                                $payload,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                            'status' => 'queued',
                            'queued_at' => $this->now(),
                        ]);
                        $this->pdo->commit();

                        return 'queued';
                    }

                    $this->pdo->commit();

                    return 'duplicate_event';
                }

                if ($this->hasQueuedTransition($dealId, $stage, $category)) {
                    $this->pdo->commit();

                    return 'duplicate_transition';
                }

                $currentState = $this->dealState($dealId);

                if (
                    ($currentState['stage'] ?? null) === $this->targetStage
                    && ($currentState['category'] ?? null) === $this->targetCategory
                ) {
                    $this->pdo->commit();

                    return 'already_in_stage';
                }

                $sql = $this->pdo->prepare(
                    'INSERT INTO incoming_events (
                        event_key, deal_id, stage, category, payload_json, status, created_at, queued_at
                     ) VALUES (
                        :event_key, :deal_id, :stage, :category, :payload_json, :status, :created_at, :queued_at
                     )'
                );
                $sql->execute([
                    'event_key' => $eventKey,
                    'deal_id' => $dealId,
                    'stage' => $stage,
                    'category' => $category,
                    'payload_json' => json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'status' => 'queued',
                    'created_at' => $this->now(),
                    'queued_at' => $this->now(),
                ]);
                $this->pdo->commit();

                return 'queued';
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        });
    }

    public function syncDealStage(int $dealId, string $stage, string $category): void
    {
        $this->withWriteRetry(function () use ($dealId, $stage, $category): void {
            $this->upsertDealState($dealId, $stage, $category);
        });
    }

    public function rememberTraceId(string $eventKey, string $traceId): string
    {
        return $this->withWriteRetry(function () use ($eventKey, $traceId): string {
            $row = $this->event($eventKey);

            if ($row === null) {
                throw new \RuntimeException('Событие не найдено при сохранении trace-id.');
            }

            $existing = trim((string) ($row['trace_id'] ?? ''));

            if ($existing !== '') {
                return $existing;
            }

            $sql = $this->pdo->prepare(
                'UPDATE incoming_events
                 SET trace_id = :trace_id
                 WHERE event_key = :event_key'
            );
            $sql->execute([
                'trace_id' => $traceId,
                'event_key' => $eventKey,
            ]);

            return $traceId;
        });
    }

    public function markDealSaved(string $eventKey): void
    {
        $this->markStep($eventKey, 'deal_saved_at');
    }

    public function markTimelineSaved(string $eventKey): void
    {
        $this->markStep($eventKey, 'timeline_saved_at');
    }

    public function markBpStarted(string $eventKey): void
    {
        $this->markStep($eventKey, 'bp_started_at');
    }

    public function markEventProcessed(string $eventKey): void
    {
        $this->withWriteRetry(function () use ($eventKey): void {
            $sql = $this->pdo->prepare(
                'UPDATE incoming_events
                 SET status = :status, processed_at = :processed_at, last_error = NULL
                 WHERE event_key = :event_key'
            );
            $sql->execute([
                'status' => 'processed',
                'processed_at' => $this->now(),
                'event_key' => $eventKey,
            ]);
        });
    }

    public function markEventFailed(string $eventKey, string $message): int
    {
        return $this->withWriteRetry(function () use ($eventKey, $message): int {
            $sql = $this->pdo->prepare(
                'UPDATE incoming_events
                 SET status = :status, processed_at = :processed_at, retry_count = retry_count + 1, last_error = :last_error
                 WHERE event_key = :event_key'
            );
            $sql->execute([
                'status' => 'failed',
                'processed_at' => $this->now(),
                'last_error' => $message,
                'event_key' => $eventKey,
            ]);

            $row = $this->event($eventKey);

            return (int) ($row['retry_count'] ?? 0);
        });
    }

    public function markEventPublishFailed(string $eventKey, string $message): void
    {
        $this->withWriteRetry(function () use ($eventKey, $message): void {
            $sql = $this->pdo->prepare(
                'UPDATE incoming_events
                 SET status = :status, processed_at = :processed_at, last_error = :last_error
                 WHERE event_key = :event_key'
            );
            $sql->execute([
                'status' => 'failed',
                'processed_at' => $this->now(),
                'last_error' => $message,
                'event_key' => $eventKey,
            ]);
        });
    }

    public function reserveTask(string $externalKey, int $dealId, string $eventKey): string
    {
        return $this->withWriteRetry(function () use ($externalKey, $dealId, $eventKey): string {
            $row = $this->taskLock($externalKey);

            if ($row !== null) {
                return (string) $row['status'];
            }

            $sql = $this->pdo->prepare(
                'INSERT INTO task_locks (
                    external_key, deal_id, event_key, status, created_at, updated_at
                 ) VALUES (
                    :external_key, :deal_id, :event_key, :status, :created_at, :updated_at
                 )'
            );
            $sql->execute([
                'external_key' => $externalKey,
                'deal_id' => $dealId,
                'event_key' => $eventKey,
                'status' => 'pending',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            return 'pending';
        });
    }

    public function markTaskCreated(string $externalKey, int $taskId): void
    {
        $this->withWriteRetry(function () use ($externalKey, $taskId): void {
            $sql = $this->pdo->prepare(
                'UPDATE task_locks
                 SET status = :status, bitrix_task_id = :bitrix_task_id, updated_at = :updated_at
                 WHERE external_key = :external_key'
            );
            $sql->execute([
                'status' => 'created',
                'bitrix_task_id' => $taskId,
                'updated_at' => $this->now(),
                'external_key' => $externalKey,
            ]);
        });
    }

    public function event(string $eventKey): ?array
    {
        $sql = $this->pdo->prepare('SELECT * FROM incoming_events WHERE event_key = :event_key');
        $sql->execute(['event_key' => $eventKey]);
        $row = $sql->fetch();

        return $row === false ? null : $row;
    }

    public function dealStateSnapshot(int $dealId): ?array
    {
        return $this->dealState($dealId);
    }

    public function queuedMessages(int $limit = 0): array
    {
        $sqlText = 'SELECT event_key, deal_id, stage, category, payload_json
            FROM incoming_events
            WHERE status = "queued"
            ORDER BY created_at, event_key';

        if ($limit > 0) {
            $sqlText .= ' LIMIT :limit';
        }

        $sql = $this->pdo->prepare($sqlText);

        if ($limit > 0) {
            $sql->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }

        $sql->execute();
        $rows = $sql->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'event_key' => (string) $row['event_key'],
                'deal_id' => (int) $row['deal_id'],
                'stage' => (string) $row['stage'],
                'category' => (string) $row['category'],
                'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            ],
            $rows
        );
    }

    public function eventCounts(): array
    {
        $sql = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM incoming_events GROUP BY status');
        $rows = $sql->fetchAll();
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['cnt'];
        }

        ksort($out);

        return $out;
    }

    public function recentEvents(int $limit = 10): array
    {
        $sql = $this->pdo->prepare(
            'SELECT event_key, deal_id, stage, category, status, retry_count, trace_id, last_error, created_at, processed_at
             FROM incoming_events
             ORDER BY created_at DESC, event_key DESC
             LIMIT :limit'
        );
        $sql->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $sql->execute();

        return $sql->fetchAll();
    }

    public function taskLocks(int $limit = 10): array
    {
        $sql = $this->pdo->prepare(
            'SELECT external_key, deal_id, event_key, status, bitrix_task_id, created_at, updated_at
             FROM task_locks
             ORDER BY created_at DESC, external_key DESC
             LIMIT :limit'
        );
        $sql->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $sql->execute();

        return $sql->fetchAll();
    }

    private function dealState(int $dealId): ?array
    {
        $sql = $this->pdo->prepare('SELECT * FROM deal_state WHERE deal_id = :deal_id');
        $sql->execute(['deal_id' => $dealId]);
        $row = $sql->fetch();

        return $row === false ? null : $row;
    }

    private function taskLock(string $externalKey): ?array
    {
        $sql = $this->pdo->prepare('SELECT * FROM task_locks WHERE external_key = :external_key');
        $sql->execute(['external_key' => $externalKey]);
        $row = $sql->fetch();

        return $row === false ? null : $row;
    }

    private function hasQueuedTransition(
        int $dealId,
        string $stage,
        string $category,
        ?string $exceptEventKey = null
    ): bool {
        $sqlText = 'SELECT event_key
            FROM incoming_events
            WHERE deal_id = :deal_id
              AND stage = :stage
              AND category = :category
              AND status = "queued"';

        if ($exceptEventKey !== null) {
            $sqlText .= ' AND event_key != :event_key';
        }

        $sqlText .= ' LIMIT 1';

        $sql = $this->pdo->prepare($sqlText);
        $params = [
            'deal_id' => $dealId,
            'stage' => $stage,
            'category' => $category,
        ];

        if ($exceptEventKey !== null) {
            $params['event_key'] = $exceptEventKey;
        }

        $sql->execute($params);

        return $sql->fetch() !== false;
    }

    private function upsertDealState(int $dealId, string $stage, string $category): void
    {
        $sql = $this->pdo->prepare(
            'INSERT INTO deal_state (deal_id, stage, category, updated_at)
             VALUES (:deal_id, :stage, :category, :updated_at)
             ON CONFLICT(deal_id) DO UPDATE SET
                stage = excluded.stage,
                category = excluded.category,
                updated_at = excluded.updated_at'
        );
        $sql->execute([
            'deal_id' => $dealId,
            'stage' => $stage,
            'category' => $category,
            'updated_at' => $this->now(),
        ]);
    }

    private function markStep(string $eventKey, string $column): void
    {
        $this->withWriteRetry(function () use ($eventKey, $column): void {
            $sql = $this->pdo->prepare(
                sprintf(
                    'UPDATE incoming_events SET %s = :value WHERE event_key = :event_key',
                    $column
                )
            );
            $sql->execute([
                'value' => $this->now(),
                'event_key' => $eventKey,
            ]);
        });
    }

    private function now(): string
    {
        return ($this->now)()->format(DATE_ATOM);
    }

    private function withWriteRetry(callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if (!$this->isSqliteBusy($e) || $attempt >= self::SQLITE_BUSY_RETRIES - 1) {
                    throw $e;
                }

                $attempt++;
                usleep((50_000 * $attempt) + random_int(0, 25_000));
            }
        }
    }

    private function ensureColumns(string $table, array $columns): void
    {
        $knownColumns = $this->tableColumns($table);

        foreach ($columns as $column => $definition) {
            if (isset($knownColumns[$column])) {
                continue;
            }

            $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
        }
    }

    private function tableColumns(string $table): array
    {
        $result = [];
        $query = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));

        foreach ($query->fetchAll() as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '') {
                $result[$name] = true;
            }
        }

        return $result;
    }

    private function isSqliteBusy(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked');
    }
}
