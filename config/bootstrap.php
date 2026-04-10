<?php

declare(strict_types=1);

use App\App\Config;
use App\App\JsonLogger;
use App\Domain\Delivery\RiskScoreCalculator;
use App\Integration\Bitrix\BitrixCrm;
use App\Integration\Bitrix\BitrixRestClient;
use App\Integration\Courier\CourierQuoteClient;
use App\Integration\Http\CurlHttpClient;
use App\Integration\Persistence\DeliveryStateStore;
use App\Integration\Queue\RabbitMqQueue;
use App\Presentation\Cli\CommandRunner;
use App\Presentation\Http\WebhookController;
use App\UseCase\IngestWebhookEvent;
use App\UseCase\ProcessDeliveryEvent;
use App\UseCase\SimulateEvents;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
$autoload = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Зависимости не установлены. Сначала выполните composer install.\n");
    exit(1);
}

require $autoload;

$envFile = $projectRoot . '/.env';

if (file_exists($envFile)) {
    Dotenv::createImmutable($projectRoot)->safeLoad();
}

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
};
$requiredEnv = static function (string $key) use ($env): string {
    $value = trim($env($key));

    if ($value === '' || str_starts_with($value, '__SET_') || str_starts_with($value, '__set_')) {
        throw new RuntimeException(sprintf('Переменная окружения %s должна быть задана в .env.', $key));
    }

    return $value;
};
$envInt = static fn (string $key, int $default): int => (int) $env($key, (string) $default);
$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env($key, $default ? '1' : '0');

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
};

$config = new Config([
    'app_url' => $env('APP_URL', 'http://localhost:8080'),
    'app_timezone' => $env('APP_TIMEZONE', 'Europe/Moscow'),
    'app_target_stage' => $env('APP_TARGET_STAGE', 'DELIVERY'),
    'app_target_category' => $env('APP_TARGET_CATEGORY', 'main'),
    'app_webhook_secret' => $requiredEnv('APP_WEBHOOK_SECRET'),
    'app_max_webhook_bytes' => $envInt('APP_MAX_WEBHOOK_BYTES', 65536),
    'app_dry_run' => $envBool('APP_DRY_RUN', false),
    'bp_enabled' => $envBool('BP_ENABLED', false),
    'bitrix_member_id' => $requiredEnv('BITRIX_MEMBER_ID'),
    'bitrix_webhook_url' => $env('BITRIX_WEBHOOK_URL', ''),
    'bitrix_app_rest_url' => $env('BITRIX_APP_REST_URL', ''),
    'bitrix_app_access_token' => $env('BITRIX_APP_ACCESS_TOKEN', ''),
    'bitrix_request_per_second' => $envInt('BITRIX_REQUESTS_PER_SECOND', 2),
    'bitrix_timeout_ms' => $envInt('BITRIX_TIMEOUT_MS', 3000),
    'bitrix_max_retries' => $envInt('BITRIX_MAX_RETRIES', 5),
    'bitrix_deal_entity_type_id' => $envInt('BITRIX_DEAL_ENTITY_TYPE_ID', 2),
    'bitrix_field_city_code_to' => $env('BITRIX_FIELD_CITY_CODE_TO', ''),
    'bitrix_field_weight_kg' => $env('BITRIX_FIELD_WEIGHT_KG', ''),
    'bitrix_field_sla_due_at' => $env('BITRIX_FIELD_SLA_DUE_AT', ''),
    'bitrix_field_risk_score' => $env('BITRIX_FIELD_RISK_SCORE', ''),
    'bitrix_field_eta_days' => $env('BITRIX_FIELD_ETA_DAYS', ''),
    'bitrix_field_delivery_zone' => $env('BITRIX_FIELD_DELIVERY_ZONE', ''),
    'bitrix_field_diagnostic_hash' => $env('BITRIX_FIELD_DIAGNOSTIC_HASH', ''),
    'bitrix_field_raw_quote_json' => $env('BITRIX_FIELD_RAW_QUOTE_JSON', ''),
    'bitrix_task_creator_id' => $envInt('BITRIX_TASK_CREATOR_ID', 0),
    'bitrix_bp_template_id' => $envInt('BITRIX_BP_TEMPLATE_ID', 0),
    'rabbitmq_host' => $env('RABBITMQ_HOST', 'rabbitmq'),
    'rabbitmq_port' => $envInt('RABBITMQ_PORT', 5672),
    'rabbitmq_user' => $requiredEnv('RABBITMQ_USER'),
    'rabbitmq_pass' => $requiredEnv('RABBITMQ_PASS'),
    'rabbitmq_vhost' => $env('RABBITMQ_VHOST', '/'),
    'rabbitmq_queue' => $env('RABBITMQ_QUEUE', 'delivery.events'),
    'db_path' => $env('DB_PATH', dirname(__DIR__) . '/var/app.sqlite'),
    'courier_quote_url' => $env('COURIER_QUOTE_URL', 'http://courier-mock:8080/v1/quote'),
    'courier_timeout_ms' => $envInt('COURIER_TIMEOUT_MS', 2000),
    'simulate_deal_ids' => $env('SIMULATE_DEAL_IDS', ''),
]);
$directory = dirname($config->dbPath);

if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException(sprintf('Не удалось создать каталог для SQLite: %s', $directory));
}

$pdo = new PDO('sqlite:' . $config->dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA journal_mode = WAL');

$now = static fn (): DateTimeImmutable => new DateTimeImmutable('now', $config->timezone);
$sleepMilliseconds = static function (int $milliseconds): void {
    if ($milliseconds <= 0) {
        return;
    }

    usleep($milliseconds * 1000);
};
$logger = new JsonLogger($now);
$httpClient = new CurlHttpClient();
$bitrixRestClient = new BitrixRestClient(
    $httpClient,
    $sleepMilliseconds,
    $logger,
    $config->bitrixWebhookUrl,
    $config->bitrixRequestsPerSecond,
    $config->bitrixMaxRetries,
    350,
    $config->bitrixTimeoutMs
);
$bitrixEventRestClient = null;

if ($config->bitrixAppRestUrl !== '' && $config->bitrixAppAccessToken !== '') {
    $bitrixEventRestClient = new BitrixRestClient(
        $httpClient,
        $sleepMilliseconds,
        $logger,
        $config->bitrixAppRestUrl,
        $config->bitrixRequestsPerSecond,
        $config->bitrixMaxRetries,
        350,
        $config->bitrixTimeoutMs,
        null,
        null,
        null,
        $config->bitrixAppAccessToken
    );
}

$bitrix = new BitrixCrm($bitrixRestClient, $config, $bitrixEventRestClient);
$stateStore = new DeliveryStateStore($pdo, $now, $config->targetStage, $config->targetCategory);
$courierQuoteClient = new CourierQuoteClient(
    $httpClient,
    $logger,
    $sleepMilliseconds,
    $config->courierQuoteUrl,
    $config->courierTimeoutMs
);
$queue = new RabbitMqQueue(
    $config->rabbitMqHost,
    $config->rabbitMqPort,
    $config->rabbitMqUser,
    $config->rabbitMqPass,
    $config->rabbitMqVhost,
    $config->rabbitMqQueue
);
$ingestWebhookEvent = new IngestWebhookEvent($config, $bitrix, $stateStore, $queue);
$processDeliveryEvent = new ProcessDeliveryEvent(
    $now,
    $logger,
    $stateStore,
    $bitrix,
    $courierQuoteClient,
    new RiskScoreCalculator(),
    $config->bpEnabled,
    $config->bitrixBpTemplateId,
    $config->bitrixTaskCreatorId
);
$simulateEvents = new SimulateEvents($config, $httpClient, $logger);
$webhookController = new WebhookController(
    $ingestWebhookEvent,
    $logger,
    $config->maxWebhookBytes
);
$commandRunner = new CommandRunner(
    $config,
    $bitrix,
    $stateStore,
    $queue,
    $processDeliveryEvent,
    $simulateEvents
);

return [
    'config' => $config,
    'stateStore' => $stateStore,
    'webhookController' => $webhookController,
    'commandRunner' => $commandRunner,
];
