<?php

declare(strict_types=1);

namespace App\Web;

final class SiteLayout
{
    /**
     * @param array<string, array{label: string, href: string}> $links
     * @param string $active
     * @param array<int, array{label: string, href: string, class?: string}> $actions
     */
    public static function renderHeader(array $links, string $active = 'home', array $actions = []): void
    {
        if ($links === []) {
            return;
        }

        $firstLink = reset($links);
        $brandHref = $links['home']['href'] ?? (is_array($firstLink) ? ($firstLink['href'] ?? '#') : '#');

        echo '<header class="site-header">';
        echo '<div class="site-header__inner">';
        echo '<div class="site-header__cluster">';
        echo '<a class="site-brand" href="' . self::escape($brandHref) . '">AIresearch</a>';
        echo '<nav class="site-nav" aria-label="Primary navigation">';
        foreach ($links as $key => $link) {
            $classes = ['site-nav__link'];
            if ($key === $active) {
                $classes[] = 'site-nav__link--active';
            }
            echo '<a class="' . implode(' ', $classes) . '" href="' . self::escape($link['href']) . '">' . self::escape($link['label']) . '</a>';
        }
        echo '</nav>';
        echo '</div>';

        if ($actions !== []) {
            echo '<div class="site-header__actions">';
            foreach ($actions as $action) {
                if (!isset($action['label'], $action['href'])) {
                    continue;
                }
                $classes = ['button'];
                if (isset($action['class']) && trim($action['class']) !== '') {
                    $classes[] = trim($action['class']);
                }
                echo '<a class="' . implode(' ', $classes) . '" href="' . self::escape($action['href']) . '">' . self::escape($action['label']) . '</a>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</header>';
    }

    public static function renderFooter(array $links, string $tagline = 'Fast briefings from the AIresearch crawler.'): void
    {
        if ($links === []) {
            return;
        }

        echo '<footer class="site-footer">';
        echo '<div class="site-footer__inner">';
        if ($tagline !== '') {
            echo '<p class="site-footer__meta">' . self::escape($tagline) . '</p>';
        }
        echo '<nav class="site-footer__links" aria-label="Footer">';
        foreach ($links as $link) {
            echo '<a href="' . self::escape($link['href']) . '">' . self::escape($link['label']) . '</a>';
        }
        echo '</nav>';
        echo '</div>';
        echo '</footer>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

