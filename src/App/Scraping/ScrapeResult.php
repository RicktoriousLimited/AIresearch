<?php

declare(strict_types=1);

namespace App\Scraping;

use function count;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

final class ScrapeResult
{
    private string $url;

    private string $title;

    private string $text;

    /** @var array<int, string> */
    private array $paragraphs;

    /**
     * @param array<int, string> $paragraphs
     */
    public function __construct(string $url, string $title, string $text, array $paragraphs)
    {
        $this->url = $url;
        $this->title = $title;
        $this->text = $text;
        $this->paragraphs = $paragraphs;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return array<int, string>
     */
    public function paragraphs(): array
    {
        return $this->paragraphs;
    }

    public function characterCount(): int
    {
        return mb_strlen($this->text);
    }

    public function paragraphCount(): int
    {
        return count($this->paragraphs);
    }

    public function preview(int $length = 320): string
    {
        if ($length <= 0) {
            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', trim($this->text));
        if ($normalised === null) {
            $normalised = trim($this->text);
        }

        if (mb_strlen($normalised) <= $length) {
            return $normalised;
        }

        return rtrim(mb_substr($normalised, 0, $length - 1)) . '…';
    }

    /**
     * @return array{url: string, title: string, characters: int, paragraphs: int, preview: string}
     */
    public function toMetaArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'characters' => $this->characterCount(),
            'paragraphs' => $this->paragraphCount(),
            'preview' => $this->preview(),
        ];
    }
}
