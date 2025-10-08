<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\User;

use InvalidArgumentException;

final class UserService
{
    public function __construct(private UserRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $profile = (array) ($payload['profile'] ?? []);

        if ($email === '' || $password === '') {
            throw new InvalidArgumentException('Email and password are required to register a user.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        if ($this->repository->findByEmail($email) !== null) {
            throw new InvalidArgumentException('An account already exists for this email address.');
        }

        $user = new User(
            'usr-' . bin2hex(random_bytes(6)),
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            roles: ['customer'],
            profile: $profile
        );

        $this->repository->save($user);

        return $user->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->repository->findByEmail($email);
        if ($user === null || !password_verify($password, $user->passwordHash())) {
            throw new InvalidArgumentException('Invalid credentials provided.');
        }

        if (!$user->isActive()) {
            throw new InvalidArgumentException('This account has been deactivated.');
        }

        return $user->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $role = null): array
    {
        $users = array_map(
            static fn(User $user): array => $user->toArray(),
            $this->repository->all()
        );

        if ($role === null) {
            return $users;
        }

        return array_values(array_filter(
            $users,
            static fn(array $user): bool => in_array($role, (array) ($user['roles'] ?? []), true)
        ));
    }

    /**
     * @param array<int, string> $roles
     *
     * @return array<string, mixed>
     */
    public function assignRoles(string $userId, array $roles): array
    {
        $user = $this->repository->findById($userId);
        if ($user === null) {
            throw new InvalidArgumentException('User not found.');
        }

        $updated = $user->withRoles($roles);
        $this->repository->save($updated);

        return $updated->toArray();
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public function updateProfile(string $userId, array $profile): array
    {
        $user = $this->repository->findById($userId);
        if ($user === null) {
            throw new InvalidArgumentException('User not found.');
        }

        $updated = $user->withProfile($profile);
        $this->repository->save($updated);

        return $updated->toArray();
    }

    public function deactivate(string $userId): void
    {
        $user = $this->repository->findById($userId);
        if ($user === null) {
            throw new InvalidArgumentException('User not found.');
        }

        $this->repository->save($user->deactivate());
    }
}

