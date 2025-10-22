<?php

declare(strict_types=1);

namespace App\Scraping;

use function array_slice;
use function array_values;
use function filter_var;
use function implode;
use function is_string;
use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_substr;
use function parse_url;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function rtrim;
use function str_replace;
use function trim;
use function gzinflate;
use function gzdecode;
use function gzuncompress;

use const FILTER_VALIDATE_URL;
use const PATHINFO_FILENAME;
use const PHP_URL_HOST;
use const PHP_URL_PATH;

final class PdfDocumentParser
{
    /**
     * @param callable(string): string $normalise
     *
     * @return array{
     *     title: string,
     *     text: string,
     *     paragraphs: array<int, string>,
     *     links: array<int, string>,
     *     meta: array<string, mixed>,
     *     content_type: string
     * }
     */
    public function parse(string $binary, string $url, callable $normalise): array
    {
        $text = trim($this->extractText($binary));
        $paragraphs = $this->buildParagraphs($text, $normalise);
        $title = $this->deriveTitle($binary, $url, $paragraphs, $normalise);
        $links = $this->extractLinks($text);

        $meta = [
            'description' => '',
            'image' => null,
            'site_name' => '',
            'published_at' => '',
            'language' => '',
            'canonical' => $url,
            'content_type' => 'application/pdf',
        ];

        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $meta['site_name'] = $host;
        }

        if ($paragraphs !== []) {
            $meta['description'] = mb_substr($paragraphs[0], 0, 280);
        }

        $body = $paragraphs === []
            ? ($text !== '' ? $this->invokeNormaliser($normalise, str_replace(["\r", "\n"], ' ', $text)) : '')
            : implode("\n\n", $paragraphs);

        return [
            'title' => $title,
            'text' => $body,
            'paragraphs' => $paragraphs,
            'links' => $links,
            'meta' => $meta,
            'content_type' => 'application/pdf',
        ];
    }

    /**
     * @param callable(string): string $normalise
     *
     * @return array<int, string>
     */
    private function buildParagraphs(string $text, callable $normalise): array
    {
        if ($text === '') {
            return [];
        }

        $normalisedBreaks = preg_replace('/\r\n?/u', "\n", $text);
        if (!is_string($normalisedBreaks)) {
            $normalisedBreaks = $text;
        }

        $chunks = preg_split('/\n{2,}/u', $normalisedBreaks);
        if ($chunks === false) {
            $chunks = [$normalisedBreaks];
        }

        $paragraphs = [];
        foreach ($chunks as $chunk) {
            if (!is_string($chunk)) {
                continue;
            }

            $clean = $this->invokeNormaliser($normalise, str_replace("\n", ' ', $chunk));
            if ($clean !== '') {
                $paragraphs[] = $clean;
            }
        }

        if ($paragraphs === [] && $normalisedBreaks !== '') {
            $fallback = $this->invokeNormaliser($normalise, str_replace("\n", ' ', $normalisedBreaks));
            if ($fallback !== '') {
                $paragraphs[] = $fallback;
            }
        }

        if ($paragraphs !== []) {
            $paragraphs = array_values(array_slice(array_unique($paragraphs), 0, 40));
        }

        return $paragraphs;
    }

    /**
     * @param array<int, string> $paragraphs
     * @param callable(string): string $normalise
     */
    private function deriveTitle(string $binary, string $url, array $paragraphs, callable $normalise): string
    {
        if (preg_match('/\/Title\s*\(((?:\\\\.|[^()])*)\)/s', $binary, $match)) {
            $candidate = $this->decodePdfString($match[1]);
            $normalised = $this->invokeNormaliser($normalise, $candidate);
            if ($normalised !== '') {
                return $normalised;
            }
        }

        if ($paragraphs !== []) {
            foreach ($paragraphs as $paragraph) {
                $candidate = trim($paragraph);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $filename = pathinfo($path, PATHINFO_FILENAME);
            if (is_string($filename) && $filename !== '') {
                $pretty = $this->invokeNormaliser($normalise, str_replace('-', ' ', $filename));
                if ($pretty !== '') {
                    return $pretty;
                }
            }
        }

        return $this->invokeNormaliser($normalise, $url);
    }

    /**
     * @return array<int, string>
     */
    private function extractLinks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (!preg_match_all('/https?:\/\/[\w\-\.\/~%\?&=#:+,;@]+/iu', $text, $matches)) {
            return [];
        }

        $links = [];
        foreach ($matches[0] as $raw) {
            if (!is_string($raw)) {
                continue;
            }

            $candidate = rtrim($raw, '.,;)');
            if ($candidate === '') {
                continue;
            }

            if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $links[] = $candidate;
        }

        return array_values(array_slice(array_unique($links), 0, 40));
    }

    private function extractText(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $fragments = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $binary, $matches)) {
            foreach ($matches[1] as $chunk) {
                if (!is_string($chunk)) {
                    continue;
                }

                $decoded = $this->decodeStream($chunk);
                $text = $this->extractTextFromStream($decoded);
                if ($text !== '') {
                    $fragments[] = $text;
                }
            }
        }

        if ($fragments === []) {
            $fallback = $this->extractTextFromStream($binary);
            if ($fallback !== '') {
                $fragments[] = $fallback;
            }
        }

        $joined = trim(implode("\n", $fragments));
        if ($joined === '') {
            return '';
        }

        $sanitised = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $joined);
        return is_string($sanitised) ? trim($sanitised) : $joined;
    }

    private function decodeStream(string $chunk): string
    {
        $attempts = [
            static fn(string $value) => @gzuncompress($value),
            static fn(string $value) => @gzdecode($value),
            static fn(string $value) => @gzinflate($value),
        ];

        foreach ($attempts as $attempt) {
            $decoded = $attempt($chunk);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $chunk;
    }

    private function extractTextFromStream(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $fragments = [];
        if (preg_match_all('/\((?:\\\\.|[^()])*\)(?=\s*(?:Tj|TJ))/s', $data, $matches)) {
            foreach ($matches[0] as $fragment) {
                if (!is_string($fragment)) {
                    continue;
                }
                $decoded = $this->decodePdfString(substr($fragment, 1, -1));
                if ($decoded !== '') {
                    $fragments[] = $decoded;
                }
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $data, $arrayMatches)) {
            foreach ($arrayMatches[1] as $segment) {
                if (!is_string($segment)) {
                    continue;
                }

                if (!preg_match_all('/\((?:\\\\.|[^()])*\)/s', $segment, $parts)) {
                    continue;
                }

                foreach ($parts[0] as $part) {
                    if (!is_string($part)) {
                        continue;
                    }

                    $decoded = $this->decodePdfString(substr($part, 1, -1));
                    if ($decoded !== '') {
                        $fragments[] = $decoded;
                    }
                }
            }
        }

        if ($fragments === []) {
            return '';
        }

        return trim(implode("\n", $fragments));
    }

    private function decodePdfString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace_callback('/\\\d{1,3}/', static function (array $match): string {
            $code = isset($match[0]) ? octdec(substr($match[0], 1)) : 0;
            return chr($code);
        }, $value);

        if (!is_string($value)) {
            $value = '';
        }

        $value = strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\b",
            '\\f' => "\f",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ]);

        if ($value === '') {
            return '';
        }

        $leading = substr($value, 0, 2);
        if ($leading === "\xFE\xFF" || $leading === "\xFF\xFE") {
            $converted = @mb_convert_encoding(substr($value, 2), 'UTF-8', $leading === "\xFE\xFF" ? 'UTF-16BE' : 'UTF-16LE');
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        } elseif (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        return trim($value);
    }

    private function invokeNormaliser(callable $normalise, string $text): string
    {
        $value = $normalise($text);
        return is_string($value) ? trim($value) : trim((string) $value);
    }
}
