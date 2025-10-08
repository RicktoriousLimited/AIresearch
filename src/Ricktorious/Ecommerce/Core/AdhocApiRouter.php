<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

/**
 * Minimal router for JSON-centric API endpoints.
 */
final class AdhocApiRouter
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $this->routes[$method][$path] = $handler;
    }

    /**
     * Dispatch the route and return a standard response payload.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, headers: array<string, string>, body: mixed}
     */
    public function dispatch(string $method, string $path, array $query = [], array $payload = []): array
    {
        $method = strtoupper($method);
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'Not Found'],
            ];
        }

        $response = $handler($query, $payload);
        if (!is_array($response) || !isset($response['body'])) {
            $response = [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $response,
            ];
        }

        $status = (int) ($response['status'] ?? 200);
        $headers = (array) ($response['headers'] ?? ['Content-Type' => 'application/json']);
        $body = $response['body'];

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @return array<string, array<string, callable>>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
