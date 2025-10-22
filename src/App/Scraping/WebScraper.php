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
use function str_contains;
use function str_ends_with;
use function stripos;
use const CURLINFO_CONTENT_TYPE;
use const CURLINFO_EFFECTIVE_URL;
use const FILTER_VALIDATE_URL;
use const PHP_URL_PATH;
use const PHP_URL_SCHEME;

final class WebScraper implements ScraperInterface
{
    private const USER_AGENT = 'AIresearchBot/1.0 (+https://github.com/)';

    private ?PdfDocumentParser $pdfParser = null;

    /**
     * Download the page located at the given URL and return a cleaned ScrapeResult.
     */
    public function scrape(string $url): ScrapeResult
    {
        $normalisedUrl = $this->normaliseUrl($url);
        $downloaded = $this->download($normalisedUrl);

        $effectiveUrl = $downloaded['url'] !== '' ? $downloaded['url'] : $normalisedUrl;

        if ($this->isPdfResponse($downloaded['content_type'], $effectiveUrl)) {
            $parsed = $this->pdfParser()->parse(
                $downloaded['body'],
                $effectiveUrl,
                fn(string $value): string => $this->normaliseText($value)
            );

            return new ScrapeResult(
                $effectiveUrl,
                $parsed['title'],
                $parsed['text'],
                $parsed['paragraphs'],
                $parsed['links'],
                $parsed['meta'],
                $parsed['content_type']
            );
        }

        $document = $this->extractDocument($downloaded['body'], $effectiveUrl);
        $paragraphs = $document['paragraphs'];
        $text = trim(implode("\n\n", $paragraphs));

        return new ScrapeResult(
            $effectiveUrl,
            $document['title'],
            $text,
            $paragraphs,
            $document['links'],
            $document['meta'],
            $document['content_type']
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

    /**
     * @return array{body: string, content_type: string, url: string}
     */
    private function download(string $url): array
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
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8']
            ]);

            $body = curl_exec($resource);
            if (!is_string($body) || $body === '') {
                $error = curl_error($resource);
                curl_close($resource);
                throw new RuntimeException($error !== '' ? $error : 'Unable to download URL.');
            }

            $contentType = (string) curl_getinfo($resource, CURLINFO_CONTENT_TYPE);
            $effectiveUrl = (string) curl_getinfo($resource, CURLINFO_EFFECTIVE_URL);

            curl_close($resource);

            return [
                'body' => $body,
                'content_type' => $contentType,
                'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8'
                ],
                'timeout' => 20,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Unable to download URL.');
        }

        $contentType = '';
        $effectiveUrl = $url;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (!is_string($headerLine)) {
                    continue;
                }

                if (stripos($headerLine, 'Content-Type:') === 0) {
                    $contentType = trim(substr($headerLine, 13));
                } elseif (stripos($headerLine, 'Location:') === 0) {
                    $location = trim(substr($headerLine, 9));
                    if ($location !== '') {
                        $effectiveUrl = $location;
                    }
                }
            }
        }

        return [
            'body' => $body,
            'content_type' => $contentType,
            'url' => $effectiveUrl,
        ];
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
                return [
                    'title' => '',
                    'paragraphs' => $this->fallbackParagraphs($html),
                    'links' => [],
                    'meta' => $meta,
                    'content_type' => 'text/html',
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

            $nodes = $xpath->query('//body//p');
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
            'content_type' => 'text/html',
        ];
    }

    private function isPdfResponse(string $contentType, string $url): bool
    {
        $contentType = trim($contentType);
        if ($contentType !== '') {
            $lower = mb_strtolower($contentType);
            if (str_contains($lower, 'application/pdf')) {
                return true;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return str_ends_with(mb_strtolower($path), '.pdf');
        }

        return false;
    }

    private function pdfParser(): PdfDocumentParser
    {
        if ($this->pdfParser === null) {
            $this->pdfParser = new PdfDocumentParser();
        }

        return $this->pdfParser;
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

    /**
     * @return array<int, string>
     */
    private function fallbackParagraphs(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $paragraphs = [];
        if (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            foreach ($matches[1] as $rawParagraph) {
                $text = $this->normaliseText(strip_tags($rawParagraph));
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        return array_values(array_unique($paragraphs));
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
