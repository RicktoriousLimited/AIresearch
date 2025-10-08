<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\CRM;

use InvalidArgumentException;

final class CRMService
{
    public function __construct(
        private CustomerRepository $customers,
        private InteractionRepository $interactions
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertCustomer(array $payload): CustomerProfile
    {
        $id = trim((string) ($payload['id'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $phone = $payload['phone'] ?? null;
        $company = $payload['company'] ?? null;
        $metadata = $this->extractMetadata($payload);
        $tags = $this->extractTags($payload['tags'] ?? null);

        $customer = null;
        if ($id !== '') {
            $customer = $this->customers->find($id);
        }
        if ($customer === null && $email !== '') {
            $customer = $this->customers->findByEmail($email);
        }

        if ($customer === null) {
            if ($name === '' && $email === '') {
                throw new InvalidArgumentException('Customers require at least a name or email address.');
            }

            $customer = CustomerProfile::create($name, $email, $phone !== null ? (string) $phone : null, $company !== null ? (string) $company : null, $tags, $metadata);
        } else {
            $customer->updateDetails(
                $name !== '' ? $name : null,
                $email !== '' ? $email : null,
                $phone !== null ? (string) $phone : null,
                $company !== null ? (string) $company : null
            );
            if ($tags !== []) {
                $customer->mergeTags($tags);
            }
            if ($metadata !== []) {
                $customer->mergeMetadata($metadata);
            }
        }

        return $this->customers->save($customer);
    }

    /**
     * @return array<int, CustomerProfile>
     */
    public function customers(): array
    {
        return array_values($this->customers->all());
    }

    public function findCustomer(string $id): ?CustomerProfile
    {
        return $this->customers->find($id);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function recordInteraction(string $customerId, string $type, array $payload = []): array
    {
        $customer = $this->customers->find($customerId);
        if ($customer === null) {
            throw new InvalidArgumentException(sprintf('Customer "%s" does not exist.', $customerId));
        }

        return $this->interactions->record($customer->id(), $type, $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function interactions(?string $customerId = null): array
    {
        if ($customerId === null) {
            return $this->interactions->all();
        }

        return $this->interactions->forCustomer($customerId);
    }

    /**
     * @return array<int, string>
     */
    private function extractTags(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = preg_split('/[,;]/', $raw) ?: [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $tags[] = strtolower($tag);
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extractMetadata(array $payload): array
    {
        $metadata = (array) ($payload['metadata'] ?? []);
        unset($metadata['id'], $metadata['email'], $metadata['name']);

        foreach (['lifecycle_stage', 'owner', 'source'] as $key) {
            if (isset($payload[$key])) {
                $metadata[$key] = $payload[$key];
            }
        }

        return $metadata;
    }
}
