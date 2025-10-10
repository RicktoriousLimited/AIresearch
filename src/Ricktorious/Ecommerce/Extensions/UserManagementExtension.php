<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Extensions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\User\OneTimePasswordManager;
use Ricktorious\Ecommerce\User\UserService;

final class UserManagementExtension implements ExtensionInterface
{
    public function __construct(private UserService $users, private OneTimePasswordManager $otp)
    {
    }

    public function getIdentifier(): string
    {
        return 'ricktorious.users';
    }

    public function registerBlocks(BlockRegistry $registry): void
    {
        // User management does not expose storefront blocks.
    }

    public function boot(ContentManager $contentManager): void
    {
        // No-op.
    }

    public function registerApis(AdhocApiRouter $router): void
    {
        $router->addRoute('POST', '/api/users/register', function (array $query, array $payload): array {
            try {
                $user = $this->users->register($payload);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['user' => $user],
            ];
        });

        $router->addRoute('POST', '/api/users/login', function (array $query, array $payload): array {
            $email = (string) ($payload['email'] ?? '');
            $password = (string) ($payload['password'] ?? '');

            if ($email === '' || $password === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'email and password are required.'],
                ];
            }

            try {
                $user = $this->users->authenticate($email, $password);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 401,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['user' => $user],
            ];
        });

        $router->addRoute('POST', '/api/users/request-otp', function (array $query, array $payload): array {
            $email = (string) ($payload['email'] ?? '');

            if ($email === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'email is required.'],
                ];
            }

            if ($this->users->findByEmail($email) === null) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'No account found for this email address.'],
                ];
            }

            try {
                $otp = $this->otp->issue($email);
            } catch (RuntimeException $exception) {
                return [
                    'status' => 500,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['message' => 'A one-time password has been generated.', 'otp' => $otp],
            ];
        });

        $router->addRoute('POST', '/api/users/reset-password', function (array $query, array $payload): array {
            $email = (string) ($payload['email'] ?? '');
            $otp = (string) ($payload['otp'] ?? '');
            $password = (string) ($payload['password'] ?? '');

            if ($email === '' || $otp === '' || $password === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'email, otp and password are required.'],
                ];
            }

            if (!$this->otp->verify($email, $otp)) {
                return [
                    'status' => 401,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'Invalid or expired one-time password.'],
                ];
            }

            try {
                $user = $this->users->resetPassword($email, $password);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            $this->otp->consume($email);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['message' => 'Password updated successfully.', 'user' => $user],
            ];
        });

        $router->addRoute('GET', '/api/users', function (array $query): array {
            $role = isset($query['role']) ? (string) $query['role'] : null;
            $userId = isset($query['id']) ? (string) $query['id'] : null;

            if ($userId !== null && $userId !== '') {
                $users = $this->users->list();
                foreach ($users as $user) {
                    if ((string) ($user['id'] ?? '') === $userId) {
                        return [
                            'status' => 200,
                            'headers' => ['Content-Type' => 'application/json'],
                            'body' => ['user' => $user],
                        ];
                    }
                }

                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'User not found'],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['users' => $this->users->list($role)],
            ];
        });

        $router->addRoute('POST', '/api/users/roles', function (array $query, array $payload): array {
            $userId = (string) ($payload['user_id'] ?? '');
            $roles = (array) ($payload['roles'] ?? []);

            if ($userId === '' || $roles === []) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'user_id and roles are required.'],
                ];
            }

            try {
                $user = $this->users->assignRoles($userId, array_map('strval', $roles));
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['user' => $user],
            ];
        });

        $router->addRoute('POST', '/api/users/profile', function (array $query, array $payload): array {
            $userId = (string) ($payload['user_id'] ?? '');
            $profile = (array) ($payload['profile'] ?? []);

            if ($userId === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'user_id is required.'],
                ];
            }

            try {
                $user = $this->users->updateProfile($userId, $profile);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['user' => $user],
            ];
        });

        $router->addRoute('POST', '/api/users/deactivate', function (array $query, array $payload): array {
            $userId = (string) ($payload['user_id'] ?? '');

            if ($userId === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'user_id is required.'],
                ];
            }

            try {
                $this->users->deactivate($userId);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 204,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => null,
            ];
        });
    }
}

