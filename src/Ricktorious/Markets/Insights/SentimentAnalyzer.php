<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

use function count;
use function max;
use function mb_strtolower;
use function min;
use function preg_match_all;
use function preg_quote;
use function str_replace;
use function trim;

final class SentimentAnalyzer
{
    /** @var array<int, string> */
    private array $positiveLexicon = [
        'outperform',
        'bullish',
        'beat',
        'growth',
        'surge',
        'strong',
        'rally',
        'upgrade',
        'opportunity',
        'record',
        'optimistic',
        'positive',
        'exceed',
    ];

    /** @var array<int, string> */
    private array $negativeLexicon = [
        'miss',
        'bearish',
        'sell-off',
        'decline',
        'drop',
        'downgrade',
        'risk',
        'concern',
        'loss',
        'lawsuit',
        'negative',
        'warning',
        'volatility',
    ];

    public function score(string $text): float
    {
        $normalised = mb_strtolower(trim(str_replace(["\n", "\r"], ' ', $text)));
        if ($normalised === '') {
            return 0.0;
        }

        $positive = $this->countOccurrences($normalised, $this->positiveLexicon);
        $negative = $this->countOccurrences($normalised, $this->negativeLexicon);

        if ($positive === 0 && $negative === 0) {
            return 0.0;
        }

        $score = ($positive - $negative) / max($positive + $negative, 1);

        return max(-1.0, min(1.0, $score));
    }

    /**
     * @param array<int, string> $lexicon
     */
    private function countOccurrences(string $text, array $lexicon): int
    {
        $total = 0;
        foreach ($lexicon as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/u';
            $matches = [];
            if (preg_match_all($pattern, $text, $matches) > 0) {
                $total += count($matches[0]);
            }
        }

        return $total;
    }
}
