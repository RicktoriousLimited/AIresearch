<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\AI;

use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;

/**
 * Simplified AI engine that surfaces popular blocks per user.
 */
final class PersonalizationEngine
{
    public function __construct(private UserBehaviorTracker $tracker)
    {
    }

    /**
     * Produce aggregate insights for the provided user.
     *
     * @return array<string, mixed>
     */
    public function insights(string $userId): array
    {
        $events = $this->tracker->eventsForUser($userId);
        $counts = [];
        foreach ($events as $event) {
            $block = (string) ($event['payload']['block'] ?? 'unknown');
            $counts[$block] = ($counts[$block] ?? 0) + 1;
        }

        arsort($counts);

        return [
            'user' => $userId,
            'total_events' => count($events),
            'block_popularity' => $counts,
        ];
    }

    /**
     * Recommend blocks to surface based on observed engagement.
     *
     * @param array<int, string> $availableBlocks
     *
     * @return array<int, string>
     */
    public function recommendBlocks(string $userId, array $availableBlocks): array
    {
        $insights = $this->insights($userId);
        $popularity = (array) ($insights['block_popularity'] ?? []);
        if ($popularity === []) {
            return $availableBlocks;
        }

        $scored = [];
        foreach ($availableBlocks as $blockName) {
            $scored[$blockName] = $popularity[$blockName] ?? 0;
        }

        arsort($scored);

        return array_keys($scored);
    }
}
