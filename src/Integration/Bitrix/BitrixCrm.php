<?php

declare(strict_types=1);

namespace App\Integration\Bitrix;

use App\App\Config;
class BitrixCrm
{
    private const DEAL_UPDATE_EVENT = 'ONCRMDEALUPDATE';
    private const TIMELINE_SCAN_PAGES = 20;

    public function __construct(
        private readonly BitrixRestClient $rest,
        private readonly Config $config,
        private readonly ?BitrixRestClient $eventRest = null
    ) {
    }

    public function loadDeal(int $dealId): array
    {
        $this->assertDealReadConfig();

        $deal = $this->rest->call('crm.deal.get', ['id' => $dealId]);
        $products = $this->rest->call('crm.deal.productrows.get', ['id' => $dealId]);

        $contactId = $this->resolveDealContactId($dealId, $deal);
        $contact = $contactId > 0
            ? $this->rest->call('crm.contact.get', ['id' => $contactId])
            : [];

        return [
            'deal' => [
                'id' => $dealId,
                'title' => (string) ($deal['TITLE'] ?? ''),
                'assigned_by_id' => (int) ($deal['ASSIGNED_BY_ID'] ?? 0),
                'created_by_id' => (int) ($deal['CREATED_BY_ID'] ?? 0),
                'contact_id' => $contactId,
                'city_code_to' => $this->dealString($deal, $this->config->bitrixFieldCityCodeTo),
                'weight_kg' => $this->dealFloat($deal, $this->config->bitrixFieldWeightKg),
                'sla_due_at' => new \DateTimeImmutable($this->dealString($deal, $this->config->bitrixFieldSlaDueAt)),
            ],
            'contact' => [
                'id' => $contactId,
                'name' => trim((string) (($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''))),
                'phone' => $this->firstContactValue($contact['PHONE'] ?? ''),
                'email' => $this->firstContactValue($contact['EMAIL'] ?? ''),
            ],
            'products' => array_map(
                static fn (array $row): array => [
                    'id' => (int) ($row['ID'] ?? 0),
                    'name' => (string) ($row['PRODUCT_NAME'] ?? $row['NAME'] ?? 'Товар'),
                    'price' => (float) ($row['PRICE'] ?? 0),
                    'quantity' => (float) ($row['QUANTITY'] ?? 0),
                ],
                is_array($products) ? $products : []
            ),
        ];
    }

    public function loadDealStage(int $dealId): array
    {
        $deal = $this->rest->call('crm.deal.get', ['id' => $dealId]);

        return [
            'id' => $dealId,
            'stage' => trim((string) ($deal['STAGE_ID'] ?? '')),
            'category' => trim((string) ($deal['CATEGORY_ID'] ?? '')),
        ];
    }

    public function loadRecentDealStageHistory(int $dealId, int $limit = 2): array
    {
        $result = $this->rest->call('crm.stagehistory.list', [
            'entityTypeId' => $this->config->bitrixDealEntityTypeId,
            'filter' => [
                'OWNER_ID' => $dealId,
            ],
            'order' => [
                'ID' => 'DESC',
            ],
            'select' => ['ID', 'OWNER_ID', 'STAGE_ID', 'CATEGORY_ID', 'CREATED_TIME'],
            'start' => -1,
            'limit' => $limit,
        ]);

        if (!is_array($result)) {
            return [];
        }

        $rows = $result;

        if (isset($result['items']) && is_array($result['items'])) {
            $rows = $result['items'];
        } elseif (!array_is_list($result)) {
            return [];
        }

        $history = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $history[] = [
                'id' => (int) ($row['ID'] ?? 0),
                'stage' => trim((string) ($row['STAGE_ID'] ?? $row['stageId'] ?? $row['stage'] ?? '')),
                'category' => trim((string) ($row['CATEGORY_ID'] ?? $row['categoryId'] ?? $row['category'] ?? '')),
                'created_time' => trim((string) ($row['CREATED_TIME'] ?? $row['createdTime'] ?? '')),
            ];
        }

        return $history;
    }

    public function updateDealResult(
        int $dealId,
        int $riskScore,
        int $etaDays,
        string $deliveryZone,
        string $diagnosticHash,
        array $rawQuote
    ): void {
        $this->assertDealWriteConfig();

        $this->rest->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => [
                $this->config->bitrixFieldRiskScore => $riskScore,
                $this->config->bitrixFieldEtaDays => $etaDays,
                $this->config->bitrixFieldDeliveryZone => $deliveryZone,
                $this->config->bitrixFieldDiagnosticHash => $diagnosticHash,
                $this->config->bitrixFieldRawQuoteJson => json_encode(
                    $rawQuote,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
        ]);
    }

    public function hasTimelineMarker(int $dealId, string $eventKey): bool
    {
        $start = 0;

        for ($page = 0; $page < self::TIMELINE_SCAN_PAGES; $page++) {
            $payload = $this->rest->callRaw('crm.timeline.logmessage.list', [
                'entityTypeId' => $this->config->bitrixDealEntityTypeId,
                'entityId' => $dealId,
                'order' => ['id' => 'desc'],
                'start' => $start,
            ]);

            [$rows, $next] = $this->timelineRowsAndNext($payload);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $text = (string) ($row['text'] ?? $row['TEXT'] ?? $row['comment'] ?? $row['COMMENT'] ?? '');

                if (str_contains($text, 'event_key=' . $eventKey)) {
                    return true;
                }
            }

            if ($next === null) {
                return false;
            }

            $start = $next;
        }

        return false;
    }

    public function addTimelineEntry(int $dealId, string $comment): void
    {
        $this->rest->call('crm.timeline.logmessage.add', [
            'fields' => [
                'entityTypeId' => $this->config->bitrixDealEntityTypeId,
                'entityId' => $dealId,
                'title' => 'Автообработка доставки',
                'text' => $comment,
                'iconCode' => 'info',
            ],
        ]);
    }

    public function findTaskIdByTitle(string $title): ?int
    {
        try {
            $result = $this->rest->call('tasks.task.list', [
                'filter' => [
                    'TITLE' => $title,
                ],
                'select' => ['ID', 'TITLE'],
                'start' => -1,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $items = [];

        if (isset($result['tasks']) && is_array($result['tasks'])) {
            $items = $result['tasks'];
        } elseif (isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        } elseif (array_is_list($result)) {
            $items = $result;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemTitle = (string) ($item['TITLE'] ?? $item['title'] ?? '');

            if ($itemTitle !== $title) {
                continue;
            }

            $taskId = (int) ($item['ID'] ?? $item['id'] ?? 0);

            if ($taskId > 0) {
                return $taskId;
            }
        }

        return null;
    }

    public function createTask(
        int $dealId,
        int $responsibleId,
        int $creatorId,
        string $title,
        string $description
    ): int {
        $result = $this->rest->call('tasks.task.add', [
            'fields' => [
                'TITLE' => $title,
                'DESCRIPTION' => $description,
                'RESPONSIBLE_ID' => $responsibleId,
                'CREATED_BY' => $creatorId,
                'UF_CRM_TASK' => ['D_' . $dealId],
            ],
        ]);

        $taskId = (int) (
            $result['task']['id']
            ?? $result['item']['id']
            ?? $result['ID']
            ?? $result['id']
            ?? 0
        );

        if ($taskId < 1) {
            throw new \RuntimeException('Метод Bitrix task.add не вернул идентификатор задачи.');
        }

        return $taskId;
    }

    public function startApprovalWorkflow(int $dealId, int $templateId): string
    {
        $result = $this->rest->call('bizproc.workflow.start', [
            'TEMPLATE_ID' => $templateId,
            'DOCUMENT_ID' => ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId],
        ]);

        $workflowId = (string) (
            $result['result']
            ?? $result['workflow_id']
            ?? $result['id']
            ?? ''
        );

        if ($workflowId === '') {
            throw new \RuntimeException('Метод Bitrix bizproc.workflow.start не вернул идентификатор workflow.');
        }

        return $workflowId;
    }

    public function ensureDealUpdateWebhook(string $handlerUrl): array
    {
        $eventRest = $this->eventRest();
        $event = self::DEAL_UPDATE_EVENT;
        $handlerUrl = rtrim($handlerUrl, '/');

        foreach ($this->listEventBindings() as $binding) {
            if (($binding['event'] ?? '') !== $event) {
                continue;
            }

            if (rtrim((string) ($binding['handler'] ?? ''), '/') !== $handlerUrl) {
                continue;
            }

            return [
                'status' => 'already_bound',
                'event' => $event,
                'handler' => $handlerUrl,
            ];
        }

        $eventRest->call('event.bind', [
            'event' => $event,
            'handler' => $handlerUrl,
        ]);

        return [
            'status' => 'bound',
            'event' => $event,
            'handler' => $handlerUrl,
        ];
    }

    private function dealString(array $deal, string $field): string
    {
        return trim((string) ($deal[$field] ?? ''));
    }

    private function assertDealReadConfig(): void
    {
        $missing = [];

        if ($this->config->bitrixFieldCityCodeTo === '') {
            $missing[] = 'BITRIX_FIELD_CITY_CODE_TO';
        }

        if ($this->config->bitrixFieldWeightKg === '') {
            $missing[] = 'BITRIX_FIELD_WEIGHT_KG';
        }

        if ($this->config->bitrixFieldSlaDueAt === '') {
            $missing[] = 'BITRIX_FIELD_SLA_DUE_AT';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Для чтения сделки из Bitrix не заданы обязательные поля в .env: ' . implode(', ', $missing)
            );
        }
    }

    private function assertDealWriteConfig(): void
    {
        $missing = [];

        if ($this->config->bitrixFieldRiskScore === '') {
            $missing[] = 'BITRIX_FIELD_RISK_SCORE';
        }

        if ($this->config->bitrixFieldEtaDays === '') {
            $missing[] = 'BITRIX_FIELD_ETA_DAYS';
        }

        if ($this->config->bitrixFieldDeliveryZone === '') {
            $missing[] = 'BITRIX_FIELD_DELIVERY_ZONE';
        }

        if ($this->config->bitrixFieldDiagnosticHash === '') {
            $missing[] = 'BITRIX_FIELD_DIAGNOSTIC_HASH';
        }

        if ($this->config->bitrixFieldRawQuoteJson === '') {
            $missing[] = 'BITRIX_FIELD_RAW_QUOTE_JSON';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Для записи результата в Bitrix не заданы обязательные поля в .env: ' . implode(', ', $missing)
            );
        }
    }

    private function resolveDealContactId(int $dealId, array $deal): int
    {
        try {
            $bindings = $this->rest->call('crm.deal.contact.items.get', ['id' => $dealId]);
        } catch (\Throwable) {
            $bindings = [];
        }

        if (is_array($bindings) && $bindings !== []) {
            $rows = array_values(array_filter($bindings, static fn (mixed $row): bool => is_array($row)));

            usort($rows, static function (array $left, array $right): int {
                $leftPrimary = (($left['IS_PRIMARY'] ?? 'N') === 'Y') ? 0 : 1;
                $rightPrimary = (($right['IS_PRIMARY'] ?? 'N') === 'Y') ? 0 : 1;

                if ($leftPrimary !== $rightPrimary) {
                    return $leftPrimary <=> $rightPrimary;
                }

                $leftSort = (int) ($left['SORT'] ?? PHP_INT_MAX);
                $rightSort = (int) ($right['SORT'] ?? PHP_INT_MAX);

                if ($leftSort !== $rightSort) {
                    return $leftSort <=> $rightSort;
                }

                return (int) ($left['CONTACT_ID'] ?? 0) <=> (int) ($right['CONTACT_ID'] ?? 0);
            });

            $contactId = (int) ($rows[0]['CONTACT_ID'] ?? 0);

            if ($contactId > 0) {
                return $contactId;
            }
        }

        return (int) ($deal['CONTACT_ID'] ?? 0);
    }

    private function dealFloat(array $deal, string $field): float
    {
        return (float) ($deal[$field] ?? 0);
    }

    private function firstContactValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $row) {
            if (is_array($row) && isset($row['VALUE'])) {
                return trim((string) $row['VALUE']);
            }

            if (is_string($row)) {
                return trim($row);
            }
        }

        return '';
    }

    private function listEventBindings(): array
    {
        $result = $this->eventRest()->call('event.get');

        return $this->collectEventBindings($result);
    }

    private function eventRest(): BitrixRestClient
    {
        if ($this->eventRest === null || !$this->eventRest->usesApplicationAuth()) {
            throw new \RuntimeException(
                'Для event.get/event.bind нужен OAuth access token приложения. Задайте BITRIX_APP_REST_URL и BITRIX_APP_ACCESS_TOKEN.'
            );
        }

        return $this->eventRest;
    }

    private function collectEventBindings(array $payload): array
    {
        $bindings = [];
        $event = (string) ($payload['event'] ?? $payload['EVENT'] ?? $payload['EVENT_NAME'] ?? '');
        $handler = (string) ($payload['handler'] ?? $payload['HANDLER'] ?? $payload['handler_url'] ?? '');

        if ($event !== '' && $handler !== '') {
            $bindings[] = [
                'event' => $event,
                'handler' => $handler,
            ];
        }

        foreach ($payload as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (is_string($key) && str_starts_with($key, 'ON')) {
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $bindings[] = [
                            'event' => $key,
                            'handler' => trim($item),
                        ];
                    }

                    if (is_array($item)) {
                        $nestedHandler = (string) ($item['handler'] ?? $item['HANDLER'] ?? $item['handler_url'] ?? '');

                        if ($nestedHandler !== '') {
                            $bindings[] = [
                                'event' => $key,
                                'handler' => $nestedHandler,
                            ];
                        }
                    }
                }

                continue;
            }

            foreach ($this->collectEventBindings($value) as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    private function timelineRowsAndNext(array $payload): array
    {
        if (isset($payload['result']) && is_array($payload['result'])) {
            $next = isset($payload['next']) ? (int) $payload['next'] : null;

            return [$payload['result'], $next];
        }

        if (array_is_list($payload)) {
            return [$payload, null];
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            $next = isset($payload['next']) ? (int) $payload['next'] : null;

            return [$payload['items'], $next];
        }

        return [[], null];
    }
}
