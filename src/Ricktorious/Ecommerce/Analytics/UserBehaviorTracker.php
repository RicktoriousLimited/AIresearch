<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Analytics;

/**
 * Captures lightweight behavioural events for personalisation.
 */
final class UserBehaviorTracker
{
    /**
     * @var array<int, array{user: string, event: string, payload: array<string, mixed>, timestamp: int}>
     */
    private array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(string $userId, string $event, array $payload = []): void
    {
        $this->events[] = [
            'user' => $userId,
            'event' => $event,
            'payload' => $payload,
            'timestamp' => time(),
        ];
    }

    /**
     * @return array<int, array{user: string, event: string, payload: array<string, mixed>, timestamp: int}>
     */
    public function allEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<int, array{user: string, event: string, payload: array<string, mixed>, timestamp: int}>
     */
    public function eventsForUser(string $userId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(array $event): bool => $event['user'] === $userId
        ));
    }
}
