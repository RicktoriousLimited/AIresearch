<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\CRM;

use RuntimeException;

final class InteractionRepository
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
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $decoded = $this->read();
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCustomer(string $customerId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $interaction): bool => ($interaction['customer_id'] ?? null) === $customerId
        ));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function record(string $customerId, string $type, array $payload = []): array
    {
        $interaction = [
            'id' => 'int-' . bin2hex(random_bytes(6)),
            'customer_id' => $customerId,
            'type' => $type,
            'payload' => $payload,
            'recorded_at' => date(DATE_ATOM),
        ];

        $interactions = $this->all();
        $interactions[] = $interaction;
        $this->write($interactions);

        return $interaction;
    }

    private function read(): mixed
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read CRM interaction storage.');
        }

        return json_decode($contents, true);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     */
    private function write(array $data): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
