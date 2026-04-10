<?php

declare(strict_types=1);

namespace App\App;

class Config
{
    public readonly string $appUrl;
    public readonly \DateTimeZone $timezone;
    public readonly string $targetStage;
    public readonly string $targetCategory;
    public readonly string $webhookSecret;
    public readonly int $maxWebhookBytes;
    public readonly bool $defaultDryRun;
    public readonly bool $bpEnabled;
    public readonly string $bitrixMemberId;
    public readonly string $bitrixWebhookUrl;
    public readonly string $bitrixAppRestUrl;
    public readonly string $bitrixAppAccessToken;
    public readonly int $bitrixRequestsPerSecond;
    public readonly int $bitrixTimeoutMs;
    public readonly int $bitrixMaxRetries;
    public readonly int $bitrixDealEntityTypeId;
    public readonly string $bitrixFieldCityCodeTo;
    public readonly string $bitrixFieldWeightKg;
    public readonly string $bitrixFieldSlaDueAt;
    public readonly string $bitrixFieldRiskScore;
    public readonly string $bitrixFieldEtaDays;
    public readonly string $bitrixFieldDeliveryZone;
    public readonly string $bitrixFieldDiagnosticHash;
    public readonly string $bitrixFieldRawQuoteJson;
    public readonly int $bitrixTaskCreatorId;
    public readonly int $bitrixBpTemplateId;
    public readonly string $rabbitMqHost;
    public readonly int $rabbitMqPort;
    public readonly string $rabbitMqUser;
    public readonly string $rabbitMqPass;
    public readonly string $rabbitMqVhost;
    public readonly string $rabbitMqQueue;
    public readonly string $dbPath;
    public readonly string $courierQuoteUrl;
    public readonly int $courierTimeoutMs;
     
    public readonly array $simulateDealIds;

    public function __construct(array $values)
    {
        $this->appUrl = (string) ($values['app_url'] ?? 'http://localhost:8080');
        $this->timezone = new \DateTimeZone((string) ($values['app_timezone'] ?? 'Europe/Moscow'));
        $this->targetStage = (string) ($values['app_target_stage'] ?? 'DELIVERY');
        $this->targetCategory = (string) ($values['app_target_category'] ?? 'main');
        $this->webhookSecret = (string) ($values['app_webhook_secret'] ?? '');
        $this->maxWebhookBytes = (int) ($values['app_max_webhook_bytes'] ?? 65536);
        $this->defaultDryRun = (bool) ($values['app_dry_run'] ?? false);
        $this->bpEnabled = (bool) ($values['bp_enabled'] ?? false);
        $this->bitrixMemberId = (string) ($values['bitrix_member_id'] ?? '');
        $this->bitrixWebhookUrl = (string) ($values['bitrix_webhook_url'] ?? '');
        $this->bitrixAppRestUrl = (string) ($values['bitrix_app_rest_url'] ?? '');
        $this->bitrixAppAccessToken = (string) ($values['bitrix_app_access_token'] ?? '');
        $this->bitrixRequestsPerSecond = (int) ($values['bitrix_request_per_second'] ?? 2);
        $this->bitrixTimeoutMs = (int) ($values['bitrix_timeout_ms'] ?? 3000);
        $this->bitrixMaxRetries = (int) ($values['bitrix_max_retries'] ?? 5);
        $this->bitrixDealEntityTypeId = (int) ($values['bitrix_deal_entity_type_id'] ?? 2);
        $this->bitrixFieldCityCodeTo = (string) ($values['bitrix_field_city_code_to'] ?? '');
        $this->bitrixFieldWeightKg = (string) ($values['bitrix_field_weight_kg'] ?? '');
        $this->bitrixFieldSlaDueAt = (string) ($values['bitrix_field_sla_due_at'] ?? '');
        $this->bitrixFieldRiskScore = (string) ($values['bitrix_field_risk_score'] ?? '');
        $this->bitrixFieldEtaDays = (string) ($values['bitrix_field_eta_days'] ?? '');
        $this->bitrixFieldDeliveryZone = (string) ($values['bitrix_field_delivery_zone'] ?? '');
        $this->bitrixFieldDiagnosticHash = (string) ($values['bitrix_field_diagnostic_hash'] ?? '');
        $this->bitrixFieldRawQuoteJson = (string) ($values['bitrix_field_raw_quote_json'] ?? '');
        $this->bitrixTaskCreatorId = (int) ($values['bitrix_task_creator_id'] ?? 0);
        $this->bitrixBpTemplateId = (int) ($values['bitrix_bp_template_id'] ?? 0);
        $this->rabbitMqHost = (string) ($values['rabbitmq_host'] ?? 'rabbitmq');
        $this->rabbitMqPort = (int) ($values['rabbitmq_port'] ?? 5672);
        $this->rabbitMqUser = (string) ($values['rabbitmq_user'] ?? '');
        $this->rabbitMqPass = (string) ($values['rabbitmq_pass'] ?? '');
        $this->rabbitMqVhost = (string) ($values['rabbitmq_vhost'] ?? '/');
        $this->rabbitMqQueue = (string) ($values['rabbitmq_queue'] ?? 'delivery.events');
        $this->dbPath = (string) ($values['db_path'] ?? dirname(__DIR__, 2) . '/var/app.sqlite');
        $this->courierQuoteUrl = (string) ($values['courier_quote_url'] ?? 'http://courier-mock:8080/v1/quote');
        $this->courierTimeoutMs = (int) ($values['courier_timeout_ms'] ?? 2000);
        $this->simulateDealIds = self::parseIds($values['simulate_deal_ids'] ?? '');
    }

    private static function parseIds(string|array $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw)));
        }

        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $raw)
        )));
    }
}
