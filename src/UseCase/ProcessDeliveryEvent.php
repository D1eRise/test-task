<?php

declare(strict_types=1);

namespace App\UseCase;

use App\App\JsonLogger;
use App\Domain\Delivery\RiskScoreCalculator;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Courier\CourierQuoteClient;
use App\Integration\Persistence\DeliveryStateStore;

class ProcessDeliveryEvent
{
    private const MAX_EVENT_RETRIES = 5;

    public function __construct(
        private readonly \Closure $now,
        private readonly JsonLogger $logger,
        private readonly DeliveryStateStore $state,
        private readonly BitrixCrm $bitrix,
        private readonly CourierQuoteClient $courierQuoteClient,
        private readonly RiskScoreCalculator $risk,
        private readonly bool $bpEnabled,
        private readonly int $bpTemplateId,
        private readonly int $taskCreatorId
    ) {
    }

    public function handle(array $message, bool $dryRun = false): void
    {
        $eventKey = (string) ($message['event_key'] ?? '');
        $dealId = (int) ($message['deal_id'] ?? 0);

        $eventRow = $this->state->event($eventKey);

        if ($eventRow === null) {
            $this->logger->warning('Сообщение из очереди пропущено: событие не найдено.', [
                'event_key' => $eventKey,
            ]);

            return;
        }

        if ((string) $eventRow['status'] === 'processed') {
            $this->logger->info('Сообщение из очереди пропущено: событие уже обработано.', [
                'event_key' => $eventKey,
            ]);

            return;
        }

        $traceId = $this->state->rememberTraceId($eventKey, $this->traceId());

        try {
            $bundle = $this->bitrix->loadDeal($dealId);
            $deal = $bundle['deal'];
            $contact = $bundle['contact'];
            $products = $bundle['products'];

            $sumProducts = 0.0;

            foreach ($products as $product) {
                $sumProducts += ((float) $product['price']) * ((float) $product['quantity']);
            }

            $quote = $this->courierQuoteClient->quote(
                [
                    'city_code_to' => (string) $deal['city_code_to'],
                    'weight_kg' => (float) $deal['weight_kg'],
                    'declared_value' => $sumProducts,
                    'trace_id' => $traceId,
                ]
            );

            $overdueHours = max(
                0,
                ((($this->now)())->getTimestamp() - $deal['sla_due_at']->getTimestamp()) / 3600
            );
            $phoneMissing = trim((string) $contact['phone']) === '';
            $emailMissing = trim((string) $contact['email']) === '';
            $risk = $this->risk->calculate(
                $phoneMissing,
                $emailMissing,
                $overdueHours,
                $sumProducts,
                (string) $quote['zone'],
                (int) $quote['eta_days']
            );
            $diagnosticHash = $this->diagHash($deal, $contact, $products, $quote);
            $comment = $this->timelineText(
                $eventKey,
                $deal,
                $contact,
                $phoneMissing,
                $emailMissing,
                $overdueHours,
                $sumProducts,
                $quote,
                $risk['score'],
                $risk['components'],
                $diagnosticHash,
                $traceId
            );

            if (!$dryRun) {
                if (($eventRow['deal_saved_at'] ?? null) === null) {
                    $this->bitrix->updateDealResult(
                        (int) $deal['id'],
                        $risk['score'],
                        (int) $quote['eta_days'],
                        (string) $quote['zone'],
                        $diagnosticHash,
                        $quote
                    );
                    $this->state->markDealSaved($eventKey);
                }

                if (($eventRow['timeline_saved_at'] ?? null) === null) {
                    if (!$this->bitrix->hasTimelineMarker((int) $deal['id'], $eventKey)) {
                        $this->bitrix->addTimelineEntry((int) $deal['id'], $comment);
                    }

                    $this->state->markTimelineSaved($eventKey);
                }

                if ($risk['score'] >= 60) {
                    $externalKey = 'risk-delivery:' . $deal['id'] . ':' . $eventKey;
                    $taskTitle = sprintf('Риск доставки по сделке #%d [%s]', $deal['id'], $eventKey);
                    $taskDescription = implode(PHP_EOL, [
                        sprintf('Риск=%d, ETA=%d, зона=%s, trace-id=%s', $risk['score'], (int) $quote['eta_days'], (string) $quote['zone'], $traceId),
                        'external_key=' . $externalKey,
                    ]);

                    $taskState = $this->state->reserveTask($externalKey, (int) $deal['id'], $eventKey);

                    if ($taskState !== 'created') {
                        $taskId = $this->bitrix->findTaskIdByTitle($taskTitle);

                        if ($taskId === null) {
                            $creatorId = $this->taskCreatorId > 0
                                ? $this->taskCreatorId
                                : max((int) $deal['assigned_by_id'], (int) $deal['created_by_id']);

                            $taskId = $this->bitrix->createTask(
                                (int) $deal['id'],
                                (int) $deal['assigned_by_id'],
                                $creatorId,
                                $taskTitle,
                                $taskDescription
                            );
                        }

                        $this->state->markTaskCreated($externalKey, $taskId);
                    }
                }

                if ($this->bpEnabled) {
                    if ($this->bpTemplateId > 0) {
                        if (($eventRow['bp_started_at'] ?? null) === null) {
                            $this->bitrix->startApprovalWorkflow((int) $deal['id'], $this->bpTemplateId);
                            $this->state->markBpStarted($eventKey);
                        }
                    } else {
                        $this->logger->warning('БП включён в конфиге, но шаг пропущен: не задан template id.', [
                            'deal_id' => $deal['id'],
                            'trace_id' => $traceId,
                        ]);
                    }
                }

                $this->state->markEventProcessed($eventKey);
            }

            $this->logger->info('Обработка DELIVERY завершена.', [
                'event_key' => $eventKey,
                'deal_id' => $deal['id'],
                'dry_run' => $dryRun,
                'risk_score' => $risk['score'],
                'eta_days' => (int) $quote['eta_days'],
                'zone' => (string) $quote['zone'],
                'trace_id' => $traceId,
            ]);
        } catch (\Throwable $exception) {
            if ($dryRun) {
                $this->logger->error('Пробный прогон обработки DELIVERY завершился ошибкой.', [
                    'event_key' => $eventKey,
                    'deal_id' => $dealId,
                    'trace_id' => $traceId,
                    'error' => $exception->getMessage(),
                    'dry_run' => true,
                ]);

                throw $exception;
            }

            $retryCount = $this->state->markEventFailed($eventKey, $exception->getMessage());
            $shouldRetry = $retryCount < self::MAX_EVENT_RETRIES;

            $this->logger->error('Обработка DELIVERY завершилась ошибкой.', [
                'event_key' => $eventKey,
                'deal_id' => $dealId,
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
                'retry_count' => $retryCount,
                'will_requeue' => $shouldRetry,
            ]);

            if ($shouldRetry) {
                throw $exception;
            }
        }
    }

    private function traceId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function diagHash(array $deal, array $contact, array $products, array $quote): string
    {
        $payload = [
            'deal' => [
                'id' => $deal['id'],
                'title' => $deal['title'],
                'assigned_by_id' => $deal['assigned_by_id'],
                'city_code_to' => $deal['city_code_to'],
                'weight_kg' => $deal['weight_kg'],
                'sla_due_at' => $deal['sla_due_at']->format(DATE_ATOM),
            ],
            'contact' => [
                'id' => $contact['id'],
                'phone' => $contact['phone'],
                'email' => $contact['email'],
            ],
            'products' => array_map(
                static fn (array $product): array => [
                    'id' => $product['id'],
                    'price' => $product['price'],
                    'quantity' => $product['quantity'],
                ],
                $products
            ),
            'quote' => $quote,
        ];

        return sha1(json_encode(
            $this->sortRecursive($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
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

    private function timelineText(
        string $eventKey,
        array $deal,
        array $contact,
        bool $phoneMissing,
        bool $emailMissing,
        float $overdueHours,
        float $sumProducts,
        array $quote,
        int $riskScore,
        array $parts,
        string $diagnosticHash,
        string $traceId
    ): string {
        $chunks = [];

        foreach ($parts as $name => $value) {
            $chunks[] = $name . ':' . $value;
        }

        return implode(PHP_EOL, [
            sprintf('Автообработка DELIVERY для сделки #%d "%s"', $deal['id'], $deal['title']),
            sprintf('trace-id=%s', $traceId),
            sprintf('event_key=%s', $eventKey),
            sprintf('сумма_товаров=%.2f зона=%s eta_days=%d diagnostic_hash=%s', $sumProducts, (string) $quote['zone'], (int) $quote['eta_days'], $diagnosticHash),
            sprintf(
                'телефон_пуст=%d email_пуст=%d просрочка_часов=%.2f risk_score=%d',
                $phoneMissing ? 1 : 0,
                $emailMissing ? 1 : 0,
                $overdueHours,
                $riskScore
            ),
            sprintf(
                'контакт=%s телефон=%s email=%s',
                $contact['name'] !== '' ? $contact['name'] : '-',
                $contact['phone'] !== '' ? $contact['phone'] : '-',
                $contact['email'] !== '' ? $contact['email'] : '-'
            ),
            'состав=' . implode(', ', $chunks),
        ]);
    }
}
