<?php

declare(strict_types=1);

namespace App\Scraping;

use function array_slice;
use function array_values;
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

    /** @var array<int, string> */
    private array $links;

    /**
     * @var array<string, mixed>
     */
    private array $meta;

    /**
     * @param array<int, string> $paragraphs
     * @param array<int, string> $links
     * @param array<string, mixed> $meta
     */
    public function __construct(string $url, string $title, string $text, array $paragraphs, array $links = [], array $meta = [])
    {
        $this->url = $url;
        $this->title = $title;
        $this->text = $text;
        $this->paragraphs = $paragraphs;
        $this->links = array_values($links);
        $this->meta = $meta;
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

    /**
     * @return array<int, string>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function thumbnail(): ?string
    {
        $image = $this->meta['image'] ?? null;
        if (!is_string($image) || trim($image) === '') {
            return null;
        }

        return $image;
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
     * @return array{url: string, title: string, characters: int, paragraphs: int, preview: string, links: array<int, string>}
     */
    public function toMetaArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'characters' => $this->characterCount(),
            'paragraphs' => $this->paragraphCount(),
            'preview' => $this->preview(),
            'links' => array_slice($this->links, 0, 20),
            'meta' => $this->meta,
        ];
    }
}
