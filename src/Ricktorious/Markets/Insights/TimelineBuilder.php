<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

use function array_sum;
use function count;
use function ksort;
use function max;
use function array_values;
use function round;

final class TimelineBuilder
{
    /**
     * @param array<int, SentimentSnapshot> $snapshots
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(array $snapshots): array
    {
        $timeline = [];

        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof SentimentSnapshot) {
                continue;
            }

            $article = $snapshot->article();
            $date = $article->publishedAt()->format('Y-m-d');

            if (!isset($timeline[$date])) {
                $timeline[$date] = [];
            }

            foreach ($snapshot->segments() as $segment => $score) {
                if (!isset($timeline[$date][$segment])) {
                    $timeline[$date][$segment] = ['scores' => [], 'articles' => []];
                }

                $timeline[$date][$segment]['scores'][] = $score;
                $timeline[$date][$segment]['articles'][] = $article->title();
            }
        }

        ksort($timeline);

        $entries = [];
        foreach ($timeline as $date => $segments) {
            foreach ($segments as $segment => $data) {
                $scores = $data['scores'] ?? [];
                if ($scores === []) {
                    continue;
                }

                $average = array_sum($scores) / max(count($scores), 1);
                $entries[] = [
                    'date' => $date,
                    'segment' => $segment,
                    'score' => round($average, 3),
                    'articles' => array_values($data['articles'] ?? []),
                ];
            }
        }

        return $entries;
    }
}
