<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\User;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function password_hash;
use function password_verify;
use function random_int;
use function strtolower;
use function trim;

use const DATE_ATOM;

final class OneTimePasswordManager
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;

        $directory = dirname($storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($storagePath)) {
            file_put_contents($storagePath, json_encode([]));
        }
    }

    /**
     * Generate a new one-time password for the provided email address.
     *
     * @return array{email: string, otp: string, expires_at: string}
     */
    public function issue(string $email, int $ttlMinutes = 15): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new RuntimeException('Email is required to issue a one-time password.');
        }

        $otp = (string) random_int(100000, 999999);
        $expires = (new DateTimeImmutable())->add(new DateInterval('PT' . $ttlMinutes . 'M'));

        $records = $this->load();
        $records = array_values(array_filter($records, static fn(array $record): bool => (string) ($record['email'] ?? '') !== $email));

        $records[] = [
            'email' => $email,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'expires_at' => $expires->format(DATE_ATOM),
        ];

        $this->save($records);

        return [
            'email' => $email,
            'otp' => $otp,
            'expires_at' => $expires->format(DATE_ATOM),
        ];
    }

    /**
     * Validate the supplied one-time password for an email address.
     */
    public function verify(string $email, string $otp): bool
    {
        $email = strtolower(trim($email));
        $otp = trim($otp);
        if ($email === '' || $otp === '') {
            return false;
        }

        $now = new DateTimeImmutable();

        foreach ($this->load() as $record) {
            if (strtolower((string) ($record['email'] ?? '')) !== $email) {
                continue;
            }

            $expiresAt = isset($record['expires_at']) ? new DateTimeImmutable((string) $record['expires_at']) : null;
            if ($expiresAt !== null && $expiresAt < $now) {
                continue;
            }

            if (password_verify($otp, (string) ($record['otp_hash'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all OTP records associated with the provided email address.
     */
    public function consume(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $records = $this->load();
        $records = array_values(array_filter($records, static fn(array $record): bool => strtolower((string) ($record['email'] ?? '')) !== $email));

        $this->save($records);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load(): array
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map(static function (array $record): array {
            $record['email'] = strtolower((string) ($record['email'] ?? ''));

            return $record;
        }, $decoded);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function save(array $records): void
    {
        $result = file_put_contents(
            $this->storagePath,
            json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist OTP records.');
        }
    }
}
