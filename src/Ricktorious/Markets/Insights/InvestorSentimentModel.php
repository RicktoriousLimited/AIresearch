<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

use Ricktorious\Markets\News\NewsArticle;

use function array_keys;
use function mb_strtolower;
use function preg_match;
use function preg_quote;
use function str_replace;
use function trim;

final class InvestorSentimentModel
{
    /** @var array<string, array<int, string>> */
    private array $segmentKeywords = [
        'retail' => ['community', 'customer', 'shoppers', 'loyalty', 'app', 'consumer', 'subscriber'],
        'institutional' => ['fund', 'portfolio manager', 'pension', 'institutional', 'sovereign', 'asset manager', 'hedge fund'],
        'analyst' => ['analyst', 'price target', 'rating', 'downgrade', 'upgrade', 'research note', 'coverage'],
        'insider' => ['executive', 'ceo', 'cfo', 'insider', 'board', 'director', 'leadership'],
    ];

    public function analyse(NewsArticle $article, float $score): SentimentSnapshot
    {
        $text = mb_strtolower(trim(str_replace(["\n", "\r"], ' ', $article->title() . ' ' . $article->summary())));

        $segments = [];
        foreach ($this->segmentKeywords as $segment => $keywords) {
            if ($this->matches($text, $keywords)) {
                $segments[$segment] = $score;
            }
        }

        if ($segments === []) {
            $segments['retail'] = $score;
        }

        return new SentimentSnapshot($article, $score, $segments);
    }

    /**
     * @param array<int, string> $keywords
     */
    private function matches(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/u';
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function segments(): array
    {
        return array_keys($this->segmentKeywords);
    }
}
