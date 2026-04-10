<?php

declare(strict_types=1);

namespace App\Integration\Http;

class CurlHttpClient
{
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutMs = 2000
    ): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new \RuntimeException('Не удалось инициализировать cURL.');
        }

        $curlHeaders = [];

        foreach ($headers as $name => $value) {
            $curlHeaders[] = sprintf('%s: %s', $name, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADER => true,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($curl);

        if ($rawResponse === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new \RuntimeException('HTTP-запрос завершился ошибкой: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerLength = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headerString = substr($rawResponse, 0, $headerLength);
        $body = substr($rawResponse, $headerLength);
        curl_close($curl);

        $parsedHeaders = [];

        foreach (explode("\r\n", trim($headerString)) as $headerLine) {
            if (!str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $parsedHeaders[trim($name)] = trim($value);
        }

        return [
            'status_code' => $statusCode,
            'body' => $body,
            'headers' => $parsedHeaders,
        ];
    }
}
