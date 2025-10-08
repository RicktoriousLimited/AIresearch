<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\User;

use RuntimeException;

final class UserRepository
{
    public function __construct(private string $storagePath)
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($this->storagePath)) {
            file_put_contents($this->storagePath, json_encode([]));
        }
    }

    /**
     * @return array<int, User>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn(array $data): User => User::fromArray($data),
            $this->read()
        ));
    }

    public function findById(string $id): ?User
    {
        foreach ($this->read() as $payload) {
            if ((string) ($payload['id'] ?? '') === $id) {
                return User::fromArray($payload);
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?User
    {
        $needle = strtolower($email);
        foreach ($this->read() as $payload) {
            if (strtolower((string) ($payload['email'] ?? '')) === $needle) {
                return User::fromArray($payload);
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $records = $this->read();
        $found = false;
        foreach ($records as $index => $payload) {
            if ((string) ($payload['id'] ?? '') === $user->id()) {
                $records[$index] = $user->toArray(true);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $records[] = $user->toArray(true);
        }

        $this->write($records);
    }

    public function delete(string $id): void
    {
        $records = array_filter(
            $this->read(),
            static fn(array $payload): bool => (string) ($payload['id'] ?? '') !== $id
        );

        $this->write(array_values($records));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function read(): array
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function write(array $records): void
    {
        $result = file_put_contents(
            $this->storagePath,
            json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist user records.');
        }
    }
}

