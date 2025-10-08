<?php

declare(strict_types=1);

namespace App\Scraping;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

use function array_unique;
use function function_exists;
use function in_array;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function mb_convert_encoding;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function strip_tags;
use function str_replace;
use function stream_context_create;
use function trim;

final class WebScraper implements ScraperInterface
{
    private const USER_AGENT = 'AIresearchBot/1.0 (+https://github.com/)';

    /**
     * Download the page located at the given URL and return a cleaned ScrapeResult.
     */
    public function scrape(string $url): ScrapeResult
    {
        $normalisedUrl = $this->normaliseUrl($url);
        $html = $this->download($normalisedUrl);
        $document = $this->extractDocument($html);

        $paragraphs = $document['paragraphs'];
        $text = trim(implode("\n\n", $paragraphs));

        return new ScrapeResult(
            $normalisedUrl,
            $document['title'],
            $text,
            $paragraphs
        );
    }

    private function normaliseUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new RuntimeException('Provide a URL to scrape.');
        }

        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            $trimmed = 'https://' . $trimmed;
        }

        return $trimmed;
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $resource = curl_init($url);
            if ($resource === false) {
                throw new RuntimeException('Unable to initialise request.');
            }

            curl_setopt_array($resource, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml']
            ]);

            $body = curl_exec($resource);
            if (!is_string($body) || $body === '') {
                $error = curl_error($resource);
                curl_close($resource);
                throw new RuntimeException($error !== '' ? $error : 'Unable to download URL.');
            }

            curl_close($resource);
            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml'
                ],
                'timeout' => 20,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Unable to download URL.');
        }

        return $body;
    }

    /**
     * @return array{title: string, paragraphs: array<int, string>}
     */
    private function extractDocument(string $html): array
    {
        $paragraphs = [];
        $title = '';

        $internalErrors = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $htmlUtf8 = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
            if ($htmlUtf8 === false) {
                $htmlUtf8 = $html;
            }

            if (!$dom->loadHTML($htmlUtf8, LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $text = $this->fallbackText($html);
                return [
                    'title' => '',
                    'paragraphs' => $text === '' ? [] : [$text],
                ];
            }

            $xpath = new DOMXPath($dom);

            foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
                foreach ($dom->getElementsByTagName($tag) as $node) {
                    if ($node->parentNode instanceof DOMNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }

            $titleNodes = $dom->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $title = $this->normaliseText($titleNodes->item(0)?->textContent ?? '');
            }

            $nodes = $xpath->query('//body//*[self::p or self::li or self::blockquote or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    $textContent = $this->normaliseText($node->textContent ?? '');
                    if ($textContent === '' || $this->isNavigationSnippet($textContent)) {
                        continue;
                    }
                    $paragraphs[] = $textContent;
                }
            }

            if ($paragraphs === []) {
                $body = $dom->getElementsByTagName('body')->item(0);
                if ($body !== null) {
                    $text = $this->normaliseText($body->textContent ?? '');
                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }
            }

            $paragraphs = array_values(array_unique($paragraphs));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        return [
            'title' => $title,
            'paragraphs' => $paragraphs,
        ];
    }

    private function normaliseText(string $text): string
    {
        $stripped = str_replace(["\r", "\t"], ' ', $text);
        $collapsed = preg_replace('/\s+/u', ' ', $stripped);
        if (!is_string($collapsed)) {
            $collapsed = $stripped;
        }

        return trim($collapsed);
    }

    private function fallbackText(string $html): string
    {
        $stripped = strip_tags($html);
        return $this->normaliseText($stripped);
    }

    private function isNavigationSnippet(string $text): bool
    {
        $lower = mb_strtolower($text);
        $banned = [
            'skip to content',
            'privacy policy',
            'terms of use',
            'sign in',
            'log in',
            'cookie policy',
            'subscribe',
            'menu',
            'newsletter',
        ];

        return in_array($lower, $banned, true);
    }
}
