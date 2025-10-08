<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\CRM;

final class CustomerProfile
{
    /**
     * @param array<int, string> $tags
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        private string $id,
        private string $name,
        private string $email,
        private ?string $phone,
        private ?string $company,
        private array $tags,
        private array $metadata,
        private string $createdAt,
        private string $updatedAt
    ) {
    }

    /**
     * @param array<int, string> $tags
     * @param array<string, mixed> $metadata
     */
    public static function create(
        string $name,
        string $email,
        ?string $phone = null,
        ?string $company = null,
        array $tags = [],
        array $metadata = []
    ): self {
        $identifier = 'cust-' . bin2hex(random_bytes(6));
        $now = date(DATE_ATOM);

        return new self(
            $identifier,
            $name,
            self::sanitizeEmail($email),
            self::normalizeNullable($phone),
            self::normalizeNullable($company),
            self::sanitizeTags($tags),
            $metadata,
            $now,
            $now
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            self::sanitizeEmail((string) ($data['email'] ?? '')),
            self::normalizeNullable($data['phone'] ?? null),
            self::normalizeNullable($data['company'] ?? null),
            self::sanitizeTags($tags),
            $metadata,
            (string) ($data['created_at'] ?? date(DATE_ATOM)),
            (string) ($data['updated_at'] ?? date(DATE_ATOM))
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    public function updateDetails(?string $name = null, ?string $email = null, ?string $phone = null, ?string $company = null): void
    {
        if ($name !== null && $name !== '') {
            $this->name = $name;
        }
        if ($email !== null && $email !== '') {
            $this->email = self::sanitizeEmail($email);
        }
        if ($phone !== null) {
            $this->phone = self::normalizeNullable($phone);
        }
        if ($company !== null) {
            $this->company = self::normalizeNullable($company);
        }
    }

    /**
     * @param array<int, string> $tags
     */
    public function mergeTags(array $tags): void
    {
        $merged = array_merge($this->tags, self::sanitizeTags($tags));
        $merged = array_values(array_unique($merged));
        $this->tags = $merged;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function mergeMetadata(array $metadata): void
    {
        $this->metadata = array_replace($this->metadata, $metadata);
    }

    public function touch(): void
    {
        $this->updatedAt = date(DATE_ATOM);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function sanitizeEmail(string $email): string
    {
        $email = trim($email);

        return $email === '' ? '' : strtolower($email);
    }

    /**
     * @param array<int, string> $tags
     *
     * @return array<int, string>
     */
    private static function sanitizeTags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $value = strtolower(trim($tag));
            if ($value === '') {
                continue;
            }
            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }

    private static function normalizeNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
