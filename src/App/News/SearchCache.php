<?php

declare(strict_types=1);

namespace App\News;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

use function array_slice;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function mkdir;
use function trim;

final class SearchCache
{
    private const MAX_ENTRIES = 120;

    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;
        $directory = dirname($storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($this->storagePath)) {
            file_put_contents($this->storagePath, json_encode([]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $key, array $payload, int $ttlMinutes = 1440): void
    {
        $key = $this->normaliseKey($key);
        if ($key === '') {
            return;
        }

        $entries = $this->readAll();
        $now = $this->safeNow();
        $expiresAt = $now !== null ? $now->add(new DateInterval('PT' . max(1, $ttlMinutes) . 'M'))->format(DateTimeImmutable::ATOM) : null;

        $entries[$key] = [
            'stored_at' => $now !== null ? $now->format(DateTimeImmutable::ATOM) : null,
            'expires_at' => $expiresAt,
            'payload' => $payload,
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = $this->pruneEntries($entries);
        }

        $this->persistAll($entries);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $key, ?int $maxAgeMinutes = 1440): ?array
    {
        $key = $this->normaliseKey($key);
        if ($key === '') {
            return null;
        }

        $entries = $this->readAll();
        if (!isset($entries[$key]) || !is_array($entries[$key])) {
            return null;
        }

        $record = $entries[$key];
        $storedAt = isset($record['stored_at']) ? $this->parseDateTime((string) $record['stored_at']) : null;
        $expiresAt = isset($record['expires_at']) ? $this->parseDateTime((string) $record['expires_at']) : null;

        if ($expiresAt !== null) {
            $now = $this->safeNow();
            if ($now !== null && $expiresAt < $now) {
                unset($entries[$key]);
                $this->persistAll($entries);

                return null;
            }
        }

        if ($maxAgeMinutes !== null && $storedAt !== null) {
            $now = $this->safeNow();
            if ($now !== null) {
                $ageMinutes = (int) (($now->getTimestamp() - $storedAt->getTimestamp()) / 60);
                if ($ageMinutes > max(0, $maxAgeMinutes)) {
                    return null;
                }
            }
        }

        $payload = $record['payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    public function fingerprint(string $query, array $filters, int $limit): string
    {
        ksort($filters);

        return hash('sha256', trim($query) . '|' . $limit . '|' . json_encode($filters));
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private function pruneEntries(array $entries): array
    {
        if ($entries === []) {
            return $entries;
        }

        $keys = array_keys($entries);
        $keys = array_slice($keys, -self::MAX_ENTRIES);

        $pruned = [];
        foreach ($keys as $key) {
            if (isset($entries[$key]) && is_array($entries[$key])) {
                $pruned[$key] = $entries[$key];
            }
        }

        return $pruned;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readAll(): array
    {
        $contents = file_get_contents($this->storagePath);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || $key === '' || !is_array($value)) {
                continue;
            }
            $entries[$key] = $value;
        }

        return $entries;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function persistAll(array $entries): void
    {
        $serialisable = [];
        foreach ($entries as $key => $value) {
            if (!is_string($key) || $key === '' || !is_array($value)) {
                continue;
            }

            $serialisable[$key] = [
                'stored_at' => isset($value['stored_at']) && $value['stored_at'] !== null
                    ? (string) $value['stored_at']
                    : null,
                'expires_at' => isset($value['expires_at']) && $value['expires_at'] !== null
                    ? (string) $value['expires_at']
                    : null,
                'payload' => $value['payload'] ?? null,
            ];
        }

        $result = file_put_contents(
            $this->storagePath,
            json_encode($serialisable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist cached search payload.');
        }
    }

    private function normaliseKey(string $value): string
    {
        return trim($value);
    }

    private function safeNow(): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable();
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($trimmed);
        } catch (Throwable $exception) {
            return null;
        }
    }
}
