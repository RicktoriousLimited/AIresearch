<?php

declare(strict_types=1);

namespace App\Scraping;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

use function array_pop;
use function array_unique;
use function array_values;
use function explode;
use function filter_var;
use function function_exists;
use function implode;
use function in_array;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function mb_convert_encoding;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function parse_url;
use function strip_tags;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function stream_context_create;
use function strpos;
use function trim;
use function substr;
use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

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
        $document = $this->extractDocument($html, $normalisedUrl);

        $paragraphs = $document['paragraphs'];
        $text = trim(implode("\n\n", $paragraphs));

        return new ScrapeResult(
            $normalisedUrl,
            $document['title'],
            $text,
            $paragraphs,
            $document['links'],
            $document['meta']
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
     * @return array{title: string, paragraphs: array<int, string>, links: array<int, string>, meta: array<string, mixed>}
     */
    private function extractDocument(string $html, string $baseUrl): array
    {
        $paragraphs = [];
        $title = '';
        $links = [];
        $meta = [
            'description' => '',
            'image' => null,
            'site_name' => '',
            'published_at' => '',
            'language' => '',
            'canonical' => '',
        ];

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
                    'links' => [],
                    'meta' => $meta,
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

            $htmlNodes = $dom->getElementsByTagName('html');
            if ($htmlNodes->length > 0) {
                $languageAttr = $htmlNodes->item(0)?->attributes?->getNamedItem('lang')?->nodeValue ?? '';
                if (is_string($languageAttr)) {
                    $meta['language'] = trim($languageAttr);
                }
            }

            foreach ($dom->getElementsByTagName('link') as $linkMeta) {
                $rel = $linkMeta->attributes?->getNamedItem('rel')?->nodeValue ?? '';
                $href = $linkMeta->attributes?->getNamedItem('href')?->nodeValue ?? '';
                if (!is_string($rel) || $rel === '' || !is_string($href) || $href === '') {
                    continue;
                }

                $relLower = mb_strtolower(trim($rel));
                if ($relLower === 'canonical') {
                    $resolved = $this->resolveLink($href, $baseUrl);
                    if ($resolved !== null) {
                        $meta['canonical'] = $resolved;
                    }
                }
            }

            foreach ($dom->getElementsByTagName('meta') as $metaNode) {
                $name = $metaNode->attributes?->getNamedItem('name')?->nodeValue ?? '';
                $property = $metaNode->attributes?->getNamedItem('property')?->nodeValue ?? '';
                $content = $metaNode->attributes?->getNamedItem('content')?->nodeValue ?? '';

                if (!is_string($content) || trim($content) === '') {
                    continue;
                }

                $key = $name !== '' ? $name : $property;
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $keyNormalised = mb_strtolower(trim($key));
                $content = trim($content);

                switch ($keyNormalised) {
                    case 'description':
                    case 'og:description':
                        if ($meta['description'] === '') {
                            $meta['description'] = $this->normaliseText($content);
                        }
                        break;
                    case 'og:image':
                    case 'twitter:image':
                    case 'twitter:image:src':
                        if (!is_string($meta['image']) || $meta['image'] === '') {
                            $resolved = $this->resolveLink($content, $baseUrl);
                            if ($resolved !== null) {
                                $meta['image'] = $resolved;
                            }
                        }
                        break;
                    case 'og:site_name':
                        if ($meta['site_name'] === '') {
                            $meta['site_name'] = $this->normaliseText($content);
                        }
                        break;
                    case 'article:published_time':
                    case 'og:updated_time':
                    case 'article:modified_time':
                        if ($meta['published_at'] === '') {
                            $meta['published_at'] = $content;
                        }
                        break;
                }
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

            foreach ($dom->getElementsByTagName('a') as $linkNode) {
                $href = $linkNode->attributes?->getNamedItem('href')?->nodeValue ?? '';
                $resolved = $this->resolveLink($href, $baseUrl);
                if ($resolved === null) {
                    continue;
                }
                $links[] = $resolved;
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
            $links = array_values(array_unique($links));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        return [
            'title' => $title,
            'paragraphs' => $paragraphs,
            'links' => $links,
            'meta' => $meta,
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

    private function resolveLink(string $href, string $baseUrl): ?string
    {
        $trimmed = trim($href);
        if ($trimmed === '' || $trimmed[0] === '#') {
            return null;
        }

        $lower = mb_strtolower($trimmed);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return null;
        }

        $hashPosition = strpos($trimmed, '#');
        if ($hashPosition !== false) {
            $trimmed = substr($trimmed, 0, $hashPosition);
        }

        if (preg_match('/^https?:\/\//i', $trimmed)) {
            return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : null;
        }

        if (str_starts_with($trimmed, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $candidate = $scheme . ':' . $trimmed;
            return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $basePath = isset($baseParts['path']) ? $baseParts['path'] : '/';

        $query = '';
        $queryPosition = strpos($trimmed, '?');
        if ($queryPosition !== false) {
            $query = substr($trimmed, $queryPosition);
            $trimmed = substr($trimmed, 0, $queryPosition);
        }

        if ($trimmed === '') {
            $path = $basePath;
        } elseif (str_starts_with($trimmed, '/')) {
            $path = $trimmed;
        } else {
            $directory = $this->baseDirectory($basePath);
            $path = $directory . $trimmed;
        }

        $normalisedPath = $this->normalisePath($path);
        $candidate = $scheme . '://' . $host . $port . $normalisedPath . $query;

        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
    }

    private function baseDirectory(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        if (substr($path, -1) === '/') {
            return $path;
        }

        $position = strrpos($path, '/');
        if ($position === false) {
            return '/';
        }

        return substr($path, 0, $position + 1);
    }

    private function normalisePath(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $segment;
        }

        $normalised = '/' . implode('/', $resolved);

        if ($path !== '' && substr($path, -1) === '/' && substr($normalised, -1) !== '/') {
            $normalised .= '/';
        }

        return $normalised === '' ? '/' : $normalised;
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
