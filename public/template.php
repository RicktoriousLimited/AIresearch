<?php
require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Web\SiteLayout;
/** @var array $results */
/** @var string $query */
/** @var int $page */
/** @var string $since */
/** @var array $sourceFilters */
/** @var string $lang */
/** @var int $dedupe */
/** @var array $facets */
/** @var int $total */
/** @var int $latency */
/** @var string $version */
/** @var string $error */
/** @var string $baseUrl */
/** @var array $timeFilters */
/** @var array $langOptions */

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$path = parse_url($baseUrl, PHP_URL_PATH) ?: '/search.php';
$directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
$basePrefix = $directory === '' || $directory === '/' ? '' : (str_starts_with($directory, '/') ? $directory : '/' . $directory);

$homeUrl = $basePrefix . '/index.php';
$searchUrl = $baseUrl;
$graphUrl = $basePrefix . '/knowledge-graph.php';
$docsUrl = $basePrefix . '/docs';

$navigationPaths = [
    'home' => $homeUrl,
    'search' => $searchUrl,
    'graph' => $graphUrl,
    'docs' => $docsUrl,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>News Intelligence Search</title>
    <link rel="stylesheet" href="/assets/theme.css" />
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body class="site site--search">
<?php SiteLayout::renderHeader($navigationPaths, 'search'); ?>
<main class="site-main" id="main">
    <div class="search-view">
        <section class="search-bar">
            <form class="search-bar__form" method="get" action="<?= esc($baseUrl); ?>">
                <input type="search" name="q" placeholder="Search the news" value="<?= esc($query); ?>" aria-label="Search the news" />
                <button type="submit" class="search-bar__submit">Search</button>
            </form>
            <?php if ($error): ?>
                <p class="search-bar__status" data-tone="error"><?= esc($error); ?></p>
            <?php endif; ?>
            <div class="search-bar__filters" role="group" aria-label="Search filters">
                <div class="search-bar__filter-group">
                    <?php foreach ($timeFilters as $key => $label): ?>
                        <?php $timeActive = $since === $key; ?>
                        <a class="filter-chip<?= $timeActive ? ' filter-chip--active' : ''; ?>" href="<?= esc(buildFilterUrl(['since' => $key, 'page' => 1])); ?>"><?= esc($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php $dedupeTarget = $dedupe ? 0 : 1; ?>
                <a class="filter-chip<?= $dedupe ? ' filter-chip--active' : ''; ?>" href="<?= esc(buildFilterUrl(['dedupe' => $dedupeTarget, 'page' => 1])); ?>">
                    <?= $dedupe ? 'Dedupe on' : 'Dedupe off'; ?>
                </a>
                <?php if (!empty($facets['source'])): ?>
                    <div class="search-bar__filter-group">
                        <?php $topSources = array_slice(array_keys($facets['source']), 0, 5); ?>
                        <?php foreach ($topSources as $src): ?>
                            <?php $active = in_array($src, $sourceFilters, true); ?>
                            <?php $newSources = $sourceFilters; ?>
                            <?php if ($active) {
                                $newSources = array_values(array_filter($newSources, static fn($s) => $s !== $src));
                            } else {
                                $newSources[] = $src;
                            } ?>
                            <?php $href = buildFilterUrl(['source' => $newSources ? $newSources : null, 'page' => 1]); ?>
                            <a class="filter-chip<?= $active ? ' filter-chip--active' : ''; ?>" href="<?= esc($href); ?>"><?= esc($src); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($langOptions)): ?>
                    <form class="filter-chip filter-chip--select" method="get" action="<?= esc($baseUrl); ?>">
                        <?= hiddenInputs(['q' => $query, 'page' => 1, 'since' => $since, 'dedupe' => $dedupe, 'source' => $sourceFilters]); ?>
                        <label>
                            <span class="filter-chip__label">Language</span>
                            <select name="lang" onchange="this.form.submit()">
                                <option value="">All</option>
                                <?php foreach ($langOptions as $option): ?>
                                    <option value="<?= esc($option); ?>"<?= strtolower($lang) === strtolower($option) ? ' selected' : ''; ?>><?= esc(strtoupper($option)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <div class="search-view__layout">
            <section class="search-results">
                <header class="search-results__header">
                    <h1 class="search-results__title">News intelligence results</h1>
                    <p class="search-results__meta">
                        <strong><?= number_format((int) $total); ?></strong> results · <?= (int) $latency; ?> ms · Index <?= esc($version); ?>
                    </p>
                </header>
                <?php if ($results): ?>
                    <ol class="result-list">
                        <?php foreach ($results as $result): ?>
                            <li>
                                <article class="result-card">
                                    <h2 class="result-card__headline">
                                        <a href="<?= esc($result['url']); ?>" target="_blank" rel="noopener noreferrer"><?= esc($result['title']); ?></a>
                                    </h2>
                                    <p class="result-card__meta">
                                        <?= esc($result['source']); ?> · <?= esc($result['time']); ?> · <?= esc(strtoupper($result['lang'])); ?>
                                        <?php if (($result['collapsed'] ?? 0) > 0): ?>
                                            <span class="result-card__badge">+<?= (int) $result['collapsed']; ?> more sources</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($result['lede'])): ?>
                                        <p class="result-card__summary"><?= esc($result['lede']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($result['entities'])): ?>
                                        <div class="result-card__entities">
                                            <?php foreach ($result['entities'] as $entity): ?>
                                                <span><?= esc($entity); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="result-list__empty">No results yet. Try searching for “markets” or “energy”.</p>
                <?php endif; ?>
                <?php if ($total > PAGE_SIZE): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php $totalPages = (int) ceil($total / PAGE_SIZE); ?>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php $isActivePage = $p === $page; ?>
                            <a class="pagination__link<?= $isActivePage ? ' pagination__link--active' : ''; ?>" href="<?= esc(buildFilterUrl(['page' => $p])); ?>"><?= $p; ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            </section>
            <aside class="search-aside">
                <?php if (!empty($facets['source']) || !empty($facets['lang'])): ?>
                    <section class="search-facets" aria-label="Search facets">
                        <?php if (!empty($facets['source'])): ?>
                            <div class="search-facets__group">
                                <h3>Sources</h3>
                                <ul>
                                    <?php foreach ($facets['source'] as $source => $count): ?>
                                        <li>
                                            <span><?= esc($source); ?></span>
                                            <span><?= number_format((int) $count); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($facets['lang'])): ?>
                            <div class="search-facets__group">
                                <h3>Languages</h3>
                                <ul>
                                    <?php foreach ($facets['lang'] as $language => $count): ?>
                                        <li>
                                            <span><?= esc($language); ?></span>
                                            <span><?= number_format((int) $count); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <div class="search-facets__group">
                            <h3>Freshness</h3>
                            <ul>
                                <li><span>24h</span><span><?= number_format((int) ($facets['date']['last24h'] ?? 0)); ?></span></li>
                                <li><span>7d</span><span><?= number_format((int) ($facets['date']['last7d'] ?? 0)); ?></span></li>
                                <li><span>30d</span><span><?= number_format((int) ($facets['date']['last30d'] ?? 0)); ?></span></li>
                            </ul>
                        </div>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
</body>
</html>
<?php
function buildFilterUrl(array $overrides): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($params);
}

function hiddenInputs(array $data): string
{
    $html = '';
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($key . '[]', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
            }
        } else {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
    }
    return $html;
}
