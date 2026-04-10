<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\App\Config;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Persistence\DeliveryStateStore;
use App\Integration\Queue\RabbitMqQueue;
use App\UseCase\ProcessDeliveryEvent;
use App\UseCase\SimulateEvents;

class CommandRunner
{
    public function __construct(
        private readonly Config $config,
        private readonly BitrixCrm $bitrix,
        private readonly DeliveryStateStore $stateStore,
        private readonly RabbitMqQueue $queue,
        private readonly ProcessDeliveryEvent $processDeliveryEvent,
        private readonly SimulateEvents $simulateEvents
    ) {
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 2));

        return match ($command) {
            'db:migrate' => $this->migrate(),
            'app:reset-state' => $this->resetState(),
            'app:subscribe-webhook' => $this->subscribeWebhook($options),
            'app:consume' => $this->consume($options),
            'app:simulate-events' => $this->simulateEvents($options),
            'app:report' => $this->report(),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknownCommand($command),
        };
    }

    private function migrate(): int
    {
        $this->stateStore->migrate();
        fwrite(STDOUT, "Миграция базы данных выполнена.\n");

        return 0;
    }

    private function resetState(): int
    {
        $this->stateStore->migrate();
        $this->queue->purge();
        $this->stateStore->reset();
        fwrite(STDOUT, "Локальное состояние сброшено, очередь очищена.\n");

        return 0;
    }

    private function subscribeWebhook(array $options): int
    {
        $handlerUrl = isset($options['handler-url']) && trim((string) $options['handler-url']) !== ''
            ? trim((string) $options['handler-url'])
            : rtrim($this->config->appUrl, '/') . '/webhook/' . $this->config->webhookSecret;

        if (!str_starts_with(strtolower($handlerUrl), 'https://')) {
            fwrite(
                STDERR,
                "Для Bitrix event.bind нужен публичный HTTPS URL обработчика. Задайте APP_URL или передайте --handler-url=https://...\n"
            );

            return 1;
        }

        try {
            fwrite(
                STDOUT,
                json_encode(
                    $this->bitrix->ensureDealUpdateWebhook($handlerUrl),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . PHP_EOL
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 1;
        }

        return 0;
    }

    private function consume(array $options): int
    {
        $this->stateStore->migrate();
        $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
        $dryRun = array_key_exists('dry-run', $options) || $this->config->defaultDryRun;

        if ($dryRun) {
            return $this->previewQueued($limit);
        }

        $processedTotal = 0;

        while (true) {
            try {
                $remaining = $limit > 0 ? max(0, $limit - $processedTotal) : 0;

                if ($limit > 0 && $remaining === 0) {
                    fwrite(STDOUT, sprintf("Обработано %d сообщений.\n", $processedTotal));

                    return 0;
                }

                $processed = $this->queue->consume(
                    function ($message) use ($dryRun): void {
                        $this->processDeliveryEvent->handle($message, $dryRun);
                    },
                    $remaining
                );

                $processedTotal += $processed;

                if ($limit > 0) {
                    fwrite(STDOUT, sprintf("Обработано %d сообщений.\n", $processedTotal));

                    return 0;
                }
            } catch (\Throwable $exception) {
                fwrite(STDERR, sprintf("Повторное подключение consumer после ошибки очереди: %s\n", $exception->getMessage()));
                sleep(2);
            }
        }
    }

    private function previewQueued(int $limit): int
    {
        $messages = $this->stateStore->queuedMessages($limit);

        foreach ($messages as $message) {
            $this->processDeliveryEvent->handle($message, true);
        }

        fwrite(STDOUT, sprintf("Просмотрено %d сообщений.\n", count($messages)));

        return 0;
    }

    private function simulateEvents(array $options): int
    {
        $count = isset($options['count']) ? (int) $options['count'] : 200;
        $duplicateEvery = isset($options['duplicate-every']) ? (int) $options['duplicate-every'] : 25;
        $dealIds = isset($options['deal-ids'])
            ? array_values(array_filter(array_map('intval', explode(',', (string) $options['deal-ids']))))
            : $this->config->simulateDealIds;

        if ($dealIds === []) {
            fwrite(STDERR, "Не заданы deal_id. Укажите SIMULATE_DEAL_IDS или передайте --deal-ids=1,2,3\n");

            return 1;
        }

        $summary = $this->simulateEvents->run($count, $dealIds, $duplicateEvery);
        fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return 0;
    }

    private function report(): int
    {
        $this->stateStore->migrate();

        $payload = [
            'events' => $this->stateStore->eventCounts(),
            'recent_events' => $this->stateStore->recentEvents(),
            'task_locks' => $this->stateStore->taskLocks(),
        ];

        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return 0;
    }

    private function help(): int
    {
        $lines = [
            'Доступные команды:',
            '  db:migrate',
            '  app:reset-state',
            '  app:subscribe-webhook [--handler-url=https://example.com/webhook/secret]',
            '  app:consume [--limit=200] [--dry-run]',
            '  app:simulate-events [--count=200] [--duplicate-every=25] [--deal-ids=1,2,3]',
            '  app:report',
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, sprintf("Неизвестная команда: %s\n", $command));

        return 1;
    }

    private function parseOptions(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                continue;
            }

            $raw = substr($argument, 2);

            if (str_contains($raw, '=')) {
                [$key, $value] = explode('=', $raw, 2);
                $options[$key] = $value;

                continue;
            }

            $options[$raw] = true;
        }

        return $options;
    }
}
