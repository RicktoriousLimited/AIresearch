<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\User;

final class User
{
    /**
     * @param array<int, string> $roles
     * @param array<string, mixed> $profile
     */
    public function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
        private array $roles = ['customer'],
        private array $profile = [],
        private string $createdAt = '',
        private ?string $updatedAt = null,
        private bool $active = true
    ) {
        if ($this->createdAt === '') {
            $this->createdAt = date(DATE_ATOM);
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profile;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param array<int, string> $roles
     */
    public function withRoles(array $roles): self
    {
        $clone = clone $this;
        $clone->roles = array_values(array_unique(array_map('strval', $roles)));
        $clone->touch();

        return $clone;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function withProfile(array $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        $clone->touch();

        return $clone;
    }

    public function deactivate(): self
    {
        $clone = clone $this;
        $clone->active = false;
        $clone->touch();

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeSensitive = false): array
    {
        $payload = [
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles,
            'profile' => $this->profile,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'active' => $this->active,
        ];

        if ($includeSensitive) {
            $payload['password_hash'] = $this->passwordHash;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['id'] ?? ''),
            (string) ($payload['email'] ?? ''),
            (string) ($payload['password_hash'] ?? ''),
            array_values(array_map('strval', (array) ($payload['roles'] ?? ['customer']))),
            (array) ($payload['profile'] ?? []),
            (string) ($payload['created_at'] ?? date(DATE_ATOM)),
            isset($payload['updated_at']) ? (string) $payload['updated_at'] : null,
            (bool) ($payload['active'] ?? true)
        );
    }

    private function touch(): void
    {
        $this->updatedAt = date(DATE_ATOM);
    }
}

