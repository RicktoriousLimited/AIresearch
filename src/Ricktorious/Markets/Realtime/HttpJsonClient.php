<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Realtime;

use RuntimeException;

final class HttpJsonClient
{
    public function __construct(private string $userAgent = 'SignalLedger/1.0')
    {
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array<string, mixed>
     */
    public function get(string $url, array $headers = [], int $timeout = 10): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
        }

        $requestHeaders = array_merge([
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ], $headers);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new RuntimeException('HTTP request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) ?: 0;
        curl_close($curl);

        if ($status >= 400) {
            throw new RuntimeException(sprintf('HTTP request failed with status %d', $status));
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Unable to decode JSON response: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected response format.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
