<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\CRM;

use RuntimeException;

final class CustomerRepository
{
    public function __construct(private string $storagePath)
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($this->storagePath)) {
            file_put_contents($this->storagePath, json_encode(new \stdClass(), JSON_PRETTY_PRINT));
        }
    }

    /**
     * @return array<string, CustomerProfile>
     */
    public function all(): array
    {
        $data = $this->read();
        $customers = [];
        foreach ($data as $id => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $customers[$id] = CustomerProfile::fromArray($payload);
        }

        return $customers;
    }

    public function find(string $id): ?CustomerProfile
    {
        $data = $this->read();
        $payload = $data[$id] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        return CustomerProfile::fromArray($payload);
    }

    public function findByEmail(string $email): ?CustomerProfile
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        foreach ($this->all() as $customer) {
            if ($customer->email() === $email) {
                return $customer;
            }
        }

        return null;
    }

    public function save(CustomerProfile $profile): CustomerProfile
    {
        $data = $this->read();
        $profile->touch();
        $data[$profile->id()] = $profile->toArray();
        $this->write($data);

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read CRM storage.');
        }

        $decoded = json_decode($contents, true);
        if ($decoded === null) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
