<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\Config;
use App\Integration\Bitrix\BitrixCrm;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBitrixRestClient;

class BitrixCrmTest extends TestCase
{
    public function testLoadsDealUsingPrimaryContactBindingBeforeDeprecatedContactId(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            return match ($method) {
                'crm.deal.get' => [
                    'ID' => (string) $params['id'],
                    'TITLE' => 'Новая сделка #1',
                    'ASSIGNED_BY_ID' => '1',
                    'CREATED_BY_ID' => '1',
                    'CONTACT_ID' => '84',
                    'UF_CRM_CITY_CODE_TO' => 'RU-MOW-77',
                    'UF_CRM_WEIGHT_KG' => '5.5',
                    'UF_CRM_SLA_DUE_AT' => '2026-04-07T12:00:00+03:00',
                ],
                'crm.deal.productrows.get' => [],
                'crm.deal.contact.items.get' => [
                    ['CONTACT_ID' => 99, 'SORT' => 200, 'IS_PRIMARY' => 'N'],
                    ['CONTACT_ID' => 100, 'SORT' => 100, 'IS_PRIMARY' => 'Y'],
                ],
                'crm.contact.get' => [
                    'ID' => '100',
                    'NAME' => 'Ирина',
                    'LAST_NAME' => 'П.',
                    'PHONE' => [['VALUE' => '+79990000000']],
                    'EMAIL' => [['VALUE' => 'irina@example.test']],
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config());
        $result = $crm->loadDeal(2002);

        self::assertSame(100, $result['contact']['id']);
        self::assertSame('+79990000000', $result['contact']['phone']);
        self::assertSame(['crm.deal.get', 'crm.deal.productrows.get', 'crm.deal.contact.items.get', 'crm.contact.get'], array_column($rest->calls, 'method'));
    }

    public function testLoadsCurrentDealStageSnapshot(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            return match ($method) {
                'crm.deal.get' => [
                    'ID' => (string) $params['id'],
                    'TITLE' => 'Новая сделка #1',
                    'TYPE_ID' => 'COMPLEX',
                    'STAGE_ID' => 'PREPARATION',
                    'PROBABILITY' => '99',
                    'CURRENCY_ID' => 'EUR',
                    'OPPORTUNITY' => '1000000.00',
                    'IS_MANUAL_OPPORTUNITY' => 'Y',
                    'TAX_VALUE' => '0.00',
                    'LEAD_ID' => null,
                    'COMPANY_ID' => '9',
                    'CONTACT_ID' => '84',
                    'QUOTE_ID' => null,
                    'BEGINDATE' => '2024-08-30T02:00:00+02:00',
                    'CLOSEDATE' => '2024-09-09T02:00:00+02:00',
                    'ASSIGNED_BY_ID' => '1',
                    'CREATED_BY_ID' => '1',
                    'MODIFY_BY_ID' => '1',
                    'DATE_CREATE' => '2024-08-30T14:29:00+02:00',
                    'DATE_MODIFY' => '2024-08-30T14:29:00+02:00',
                    'OPENED' => 'Y',
                    'CLOSED' => 'N',
                    'COMMENTS' => '[B]Пример комментария[/B]',
                    'ADDITIONAL_INFO' => 'Дополнительная информация',
                    'LOCATION_ID' => null,
                    'CATEGORY_ID' => '0',
                    'STAGE_SEMANTIC_ID' => 'P',
                    'IS_NEW' => 'N',
                    'IS_RECURRING' => 'N',
                    'IS_RETURN_CUSTOMER' => 'N',
                    'IS_REPEATED_APPROACH' => 'N',
                    'SOURCE_ID' => 'CALLBACK',
                    'SOURCE_DESCRIPTION' => 'Дополнительно об источнике',
                    'ORIGINATOR_ID' => null,
                    'ORIGIN_ID' => null,
                    'MOVED_BY_ID' => '1',
                    'MOVED_TIME' => '2024-08-30T14:29:00+02:00',
                    'LAST_ACTIVITY_TIME' => '2024-08-30T14:29:00+02:00',
                    'UTM_SOURCE' => 'google',
                    'UTM_MEDIUM' => 'CPC',
                    'UTM_CAMPAIGN' => null,
                    'UTM_CONTENT' => null,
                    'UTM_TERM' => null,
                    'PARENT_ID_1220' => '22',
                    'LAST_ACTIVITY_BY' => '1',
                    'UF_CRM_1721244482250' => 'Привет мир!',
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config());

        self::assertSame([
            'id' => 2002,
            'stage' => 'PREPARATION',
            'category' => '0',
        ], $crm->loadDealStage(2002));
    }

    public function testLoadsRecentDealStageHistory(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array {
            return match ($method) {
                'crm.stagehistory.list' => [
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
                        [
                            'ID' => 34,
                            'TYPE_ID' => 1,
                            'OWNER_ID' => 21,
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

        $crm = new BitrixCrm($rest, $this->config());

        self::assertSame([
            [
                'id' => 35,
                'stage' => 'NEW',
                'category' => '0',
                'created_time' => '2024-04-25T14:59:11+00:00',
            ],
            [
                'id' => 34,
                'stage' => 'PREPARATION',
                'category' => '0',
                'created_time' => '2024-04-24T14:59:11+00:00',
            ],
        ], $crm->loadRecentDealStageHistory(2002));
    }

    public function testFindsTimelineMarkerWithUppercaseTextField(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array {
            return match ($method) {
                'crm.timeline.logmessage.list' => [
                    [
                        'TEXT' => 'trace-id=abc' . PHP_EOL . 'event_key=evt-77',
                    ],
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config());

        self::assertTrue($crm->hasTimelineMarker(2002, 'evt-77'));
    }

    public function testFindsTimelineMarkerOnSecondPage(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            if ($method !== 'crm.timeline.logmessage.list') {
                throw new \RuntimeException('Неожиданный метод: ' . $method);
            }

            return match ((int) ($params['start'] ?? 0)) {
                0 => [
                    'result' => [
                        ['TEXT' => 'trace-id=abc'],
                    ],
                    'next' => 50,
                ],
                50 => [
                    'result' => [
                        ['TEXT' => 'trace-id=abc' . PHP_EOL . 'event_key=evt-99'],
                    ],
                ],
                default => [],
            };
        });

        $crm = new BitrixCrm($rest, $this->config());

        self::assertTrue($crm->hasTimelineMarker(2002, 'evt-99'));
    }

    public function testReturnsAlreadyBoundWhenHandlerExists(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array {
            return match ($method) {
                'event.get' => [
                    'events' => [
                        [
                            'event' => 'ONCRMDEALUPDATE',
                            'handler' => 'https://demo.test/webhook/secret',
                        ],
                    ],
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config(), $rest);
        $result = $crm->ensureDealUpdateWebhook('https://demo.test/webhook/secret/');

        self::assertSame([
            'status' => 'already_bound',
            'event' => 'ONCRMDEALUPDATE',
            'handler' => 'https://demo.test/webhook/secret',
        ], $result);
        self::assertSame(['event.get'], array_column($rest->calls, 'method'));
    }

    public function testReturnsAlreadyBoundWhenEventGetUsesEventNameMap(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array {
            return match ($method) {
                'event.get' => [
                    'ONCRMDEALUPDATE' => [
                        'https://demo.test/webhook/secret',
                    ],
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config(), $rest);
        $result = $crm->ensureDealUpdateWebhook('https://demo.test/webhook/secret');

        self::assertSame([
            'status' => 'already_bound',
            'event' => 'ONCRMDEALUPDATE',
            'handler' => 'https://demo.test/webhook/secret',
        ], $result);
        self::assertSame(['event.get'], array_column($rest->calls, 'method'));
    }

    public function testBindsWebhookWhenMissing(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method, array $params): array {
            return match ($method) {
                'event.get' => [],
                'event.bind' => [
                    'ok' => true,
                    'echo' => $params,
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config(), $rest);
        $result = $crm->ensureDealUpdateWebhook('https://demo.test/webhook/secret');

        self::assertSame([
            'status' => 'bound',
            'event' => 'ONCRMDEALUPDATE',
            'handler' => 'https://demo.test/webhook/secret',
        ], $result);
        self::assertSame('event.bind', $rest->calls[1]['method']);
        self::assertSame([
            'event' => 'ONCRMDEALUPDATE',
            'handler' => 'https://demo.test/webhook/secret',
        ], $rest->calls[1]['params']);
    }

    public function testFailsClosedWhenEventGetCannotBeRead(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array|\Throwable {
            return match ($method) {
                'event.get' => new \RuntimeException('доступ запрещён'),
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config(), $rest);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('доступ запрещён');

        $crm->ensureDealUpdateWebhook('https://demo.test/webhook/secret');
    }

    public function testRefusesToBindWebhookWithoutApplicationAuthClient(): void
    {
        $crm = new BitrixCrm(
            new FakeBitrixRestClient(function (): array {
                return [];
            }),
            $this->config()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BITRIX_APP_REST_URL');

        $crm->ensureDealUpdateWebhook('https://demo.test/webhook/secret');
    }

    public function testReadsWorkflowIdFromScalarResultField(): void
    {
        $rest = new FakeBitrixRestClient(function (string $method): array {
            return match ($method) {
                'bizproc.workflow.start' => [
                    'result' => 'wf-123',
                ],
                default => throw new \RuntimeException('Неожиданный метод: ' . $method),
            };
        });

        $crm = new BitrixCrm($rest, $this->config());

        self::assertSame('wf-123', $crm->startApprovalWorkflow(2002, 17));
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
}
