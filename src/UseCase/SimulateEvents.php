<?php

declare(strict_types=1);

namespace App\UseCase;

use App\App\Config;
use App\App\JsonLogger;
use App\Integration\Http\CurlHttpClient;

class SimulateEvents
{
    public function __construct(
        private readonly Config $config,
        private readonly CurlHttpClient $httpClient,
        private readonly JsonLogger $logger
    ) {
    }

     
    public function run(int $count, array $dealIds, int $duplicateEvery = 25): array
    {
        $url = rtrim($this->config->appUrl, '/') . '/webhook/' . $this->config->webhookSecret;
        $queued = 0;
        $duplicate = 0;
        $ignored = 0;
        $lastPayload = null;
        $baselineSent = [];
        $transitionSent = [];
        $baseTs = time();

        for ($index = 1; $index <= $count; $index++) {
            $dealId = $dealIds[($index - 1) % count($dealIds)];
            $stage = $this->config->targetStage;
            $category = $this->config->targetCategory;
            $ts = (string) ($baseTs + $index);

            if ($duplicateEvery > 0 && $index % $duplicateEvery === 0 && $lastPayload !== null) {
                $payload = $lastPayload;
            } else {
                if (!isset($baselineSent[$dealId])) {
                    $stage = 'PROCESS';
                    $baselineSent[$dealId] = true;
                } elseif (!isset($transitionSent[$dealId])) {
                    $transitionSent[$dealId] = true;
                }

                $payload = [
                    'event' => 'ONCRMDEALUPDATE',
                    'event_handler_id' => '201',
                    'simulate' => true,
                    'simulate_current' => [
                        'stage' => $stage,
                        'category' => $category,
                    ],
                    'data' => [
                        'FIELDS' => [
                            'ID' => (string) $dealId,
                        ],
                    ],
                    'ts' => $ts,
                    'auth' => [
                        'access_token' => 'simulated-access-token',
                        'expires_in' => '3600',
                        'scope' => 'crm',
                        'member_id' => $this->config->bitrixMemberId,
                        'domain' => 'some-domain.bitrix24.com',
                        'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                        'status' => 'F',
                        'client_endpoint' => 'https://some-domain.bitrix24.com/rest/',
                        'refresh_token' => 'simulated-refresh-token',
                        'application_token' => 'simulated-application-token',
                    ],
                ];

                $lastPayload = $payload;
            }

            $response = $this->httpClient->send(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                3000
            );

             
            $body = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
            $status = (string) ($body['status'] ?? 'unknown');

            if ($status === 'queued') {
                $queued++;
            } elseif ($status === 'duplicate') {
                $duplicate++;
            } else {
                $ignored++;
            }
        }

        $summary = [
            'requested' => $count,
            'queued' => $queued,
            'duplicate' => $duplicate,
            'ignored' => $ignored,
        ];

        $this->logger->info('Симуляция событий завершена.', $summary);

        return $summary;
    }
}
