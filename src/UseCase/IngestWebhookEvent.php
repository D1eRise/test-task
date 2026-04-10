<?php

declare(strict_types=1);

namespace App\UseCase;

use App\App\Config;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Persistence\DeliveryStateStore;
use App\Integration\Queue\RabbitMqQueue;

class IngestWebhookEvent
{
    public function __construct(
        private readonly Config $config,
        private readonly BitrixCrm $bitrix,
        private readonly DeliveryStateStore $state,
        private readonly RabbitMqQueue $queue
    ) {
    }

    public function handle(array $payload): array
    {
        $memberId = (string) ($payload['member_id'] ?? $payload['auth']['member_id'] ?? '');

        if ($memberId !== $this->config->bitrixMemberId) {
            return ['status' => 'forbidden', 'message' => 'member_id не совпадает'];
        }

        $dealId = $this->extractDealId($payload);
        $eventKey = $this->resolveEventKey($payload);

        if ($dealId === null) {
            return ['status' => 'invalid', 'message' => 'Не передан deal_id'];
        }

        $eventRow = $this->state->event($eventKey);
        $retryFailedEvent = $eventRow !== null && (string) ($eventRow['status'] ?? '') === 'failed';

        if ($eventRow !== null && !$retryFailedEvent) {
            return ['status' => 'duplicate', 'event_key' => $eventKey, 'message' => 'Событие с таким ключом уже встречалось'];
        }

        $observed = $this->resolveObservedDealState($dealId, $payload);
        $stage = $observed['stage'];
        $category = $observed['category'];
        $lastState = $this->state->dealStateSnapshot($dealId);
        $isTarget = $stage === $this->config->targetStage && $category === $this->config->targetCategory;
        $wasSameTarget = $lastState !== null
            && (string) ($lastState['stage'] ?? '') === $this->config->targetStage
            && (string) ($lastState['category'] ?? '') === $this->config->targetCategory;

        if (!$isTarget) {
            $this->state->syncDealStage($dealId, $stage, $category);

            return ['status' => 'ignored', 'message' => 'Событие вне настроенной стадии или категории'];
        }

        if (!$retryFailedEvent && ($wasSameTarget || (
            (string) ($lastState['stage'] ?? '') === $stage
            && (string) ($lastState['category'] ?? '') === $category
        ))) {
            return [
                'status' => 'ignored',
                'event_key' => $eventKey,
                'message' => 'Сделка не вошла в целевую стадию',
            ];
        }

        if (!$retryFailedEvent && $lastState === null && !$this->isConfirmedTargetEntry($dealId, $stage, $category, $payload)) {
            $this->state->syncDealStage($dealId, $stage, $category);

            return [
                'status' => 'ignored',
                'event_key' => $eventKey,
                'message' => 'Не удалось подтвердить переход сделки в целевую стадию',
            ];
        }

        $state = $this->state->reserveDeliveryEvent(
            $eventKey,
            $dealId,
            $stage,
            $category,
            $payload
        );

        if ($state === 'duplicate_event') {
            return ['status' => 'duplicate', 'event_key' => $eventKey, 'message' => 'Событие с таким ключом уже встречалось'];
        }

        if ($state === 'duplicate_transition') {
            return ['status' => 'ignored', 'event_key' => $eventKey, 'message' => 'Переход в DELIVERY уже стоит в очереди'];
        }

        if ($state === 'already_in_stage') {
            return ['status' => 'ignored', 'event_key' => $eventKey, 'message' => 'Сделка уже находится в целевой стадии'];
        }

        try {
            $this->queue->publish([
                'event_key' => $eventKey,
                'deal_id' => $dealId,
                'stage' => $stage,
                'category' => $category,
                'payload' => $payload,
            ]);
            $this->state->syncDealStage($dealId, $stage, $category);
        } catch (\Throwable $e) {
            $this->state->markEventPublishFailed($eventKey, 'Не удалось опубликовать событие в очередь: ' . $e->getMessage());
            throw $e;
        }

        return ['status' => 'queued', 'event_key' => $eventKey];
    }

    private function extractDealId(array $payload): ?int
    {
        if (isset($payload['deal_id'])) {
            return (int) $payload['deal_id'];
        }

        if (isset($payload['data']['FIELDS']['ID'])) {
            return (int) $payload['data']['FIELDS']['ID'];
        }

        return null;
    }

    private function extractStage(array $payload): ?string
    {
        if (isset($payload['simulate_current']['stage'])) {
            return (string) $payload['simulate_current']['stage'];
        }

        if (isset($payload['stage'])) {
            return (string) $payload['stage'];
        }

        return null;
    }

    private function extractCategory(array $payload): ?string
    {
        if (isset($payload['simulate_current']['category'])) {
            return (string) $payload['simulate_current']['category'];
        }

        if (isset($payload['category'])) {
            return (string) $payload['category'];
        }

        return null;
    }

    private function resolveObservedDealState(int $dealId, array $payload): array
    {
        if ($this->useSimulatedCurrentState($payload)) {
            $stage = $this->extractStage($payload);
            $category = $this->extractCategory($payload);

            if ($stage === null || $category === null) {
                throw new \RuntimeException('Для симуляции webhook нужно передать stage/category.');
            }

            return [
                'id' => $dealId,
                'stage' => $stage,
                'category' => $category,
            ];
        }

        $current = $this->bitrix->loadDealStage($dealId);

        return [
            'id' => $dealId,
            'stage' => (string) $current['stage'],
            'category' => (string) $current['category'],
        ];
    }

    private function useSimulatedCurrentState(array $payload): bool
    {
        $value = $payload['simulate'] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveEventKey(array $payload): string
    {
        if (isset($payload['event_id']) && $payload['event_id'] !== '') {
            return (string) $payload['event_id'];
        }

        $sanitized = $this->sanitizePayloadForEventKey($payload);

        return sha1(json_encode(
            $this->sortRecursive($sanitized),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function isConfirmedTargetEntry(int $dealId, string $stage, string $category, array $payload): bool
    {
        if ($this->useSimulatedCurrentState($payload)) {
            return true;
        }

        try {
            $history = $this->bitrix->loadRecentDealStageHistory($dealId);
        } catch (\Throwable) {
            return false;
        }

        if ($history === []) {
            return false;
        }

        $currentIndex = null;

        foreach ($history as $index => $row) {
            if (($row['stage'] ?? '') !== $stage || ($row['category'] ?? '') !== $category) {
                continue;
            }

            $currentIndex = $index;
            break;
        }

        if ($currentIndex === null) {
            return false;
        }

        for ($index = $currentIndex + 1, $count = count($history); $index < $count; $index++) {
            $previousStage = (string) ($history[$index]['stage'] ?? '');
            $previousCategory = (string) ($history[$index]['category'] ?? '');

            if ($previousStage === '' && $previousCategory === '') {
                continue;
            }

            return $previousStage !== $stage || $previousCategory !== $category;
        }

        return false;
    }

    private function sanitizePayloadForEventKey(array $payload): array
    {
        if (isset($payload['auth']) && is_array($payload['auth'])) {
            $payload['auth'] = [
                'member_id' => (string) ($payload['auth']['member_id'] ?? ''),
                'domain' => (string) ($payload['auth']['domain'] ?? ''),
            ];
        }

        return $payload;
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
