<?php
require_once __DIR__ . '/../src/App/bootstrap.php';
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

$resultsCount = count($results);
$totalMatches = max(0, (int) $total);

function formatNumberValue(?int $value): string
{
    if ($value === null) {
        return '0';
    }

    return number_format($value);
}

function formatLatency(?int $value): string
{
    if ($value === null || $value <= 0) {
        return '—';
    }

    return number_format($value) . ' ms';
}

$status = 'Start exploring the AIresearch index.';
if ($error !== '') {
    $status = 'Search results are temporarily unavailable.';
} elseif ($query !== '') {
    if ($totalMatches === 0) {
        $status = sprintf('No matches for “%s”.', $query);
    } elseif ($totalMatches <= PAGE_SIZE || $totalMatches <= $resultsCount) {
        $status = sprintf(
            'Showing %s match%s for “%s”.',
            formatNumberValue($totalMatches),
            $totalMatches === 1 ? '' : 'es',
            $query
        );
    } else {
        $status = sprintf(
            'Showing %s of %s match%s for “%s”.',
            formatNumberValue($resultsCount),
            formatNumberValue($totalMatches),
            $totalMatches === 1 ? '' : 'es',
            $query
        );
    }
} elseif ($resultsCount > 0) {
    $status = sprintf('Streaming %s indexed sources.', formatNumberValue($totalMatches));
}

$metricCards = [];
$metricCards[] = [
    'label' => 'Matching results',
    'value' => formatNumberValue($totalMatches),
    'hint' => $query === '' ? 'Across latest index' : 'Total matches',
];
$metricCards[] = [
    'label' => 'On this page',
    'value' => formatNumberValue($resultsCount),
    'hint' => $resultsCount === 1 ? 'Single result' : 'Visible results',
];
if ($latency > 0) {
    $metricCards[] = [
        'label' => 'Latency',
        'value' => formatLatency($latency),
        'hint' => 'Search time',
    ];
}
if ($version !== '') {
    $metricCards[] = [
        'label' => 'Index version',
        'value' => $version,
        'hint' => 'Manifest build',
    ];
}

$activeFilters = [];
if ($since !== 'all' && isset($timeFilters[$since])) {
    $activeFilters[] = [
        'label' => 'Recency',
        'value' => $timeFilters[$since],
        'removeUrl' => buildFilterUrl(['since' => null, 'page' => null]),
    ];
}
if ($lang !== '') {
    $activeFilters[] = [
        'label' => 'Language',
        'value' => strtoupper($lang),
        'removeUrl' => buildFilterUrl(['lang' => null, 'page' => null]),
    ];
}
foreach ($sourceFilters as $source) {
    $source = (string) $source;
    if ($source === '') {
        continue;
    }
    $remainingSources = array_values(array_filter(
        $sourceFilters,
        static fn($value) => (string) $value !== $source
    ));
    $activeFilters[] = [
        'label' => 'Source',
        'value' => $source,
        'removeUrl' => buildFilterUrl([
            'source' => $remainingSources === [] ? null : $remainingSources,
            'page' => null,
        ]),
    ];
}
if ((int) $dedupe === 0) {
    $activeFilters[] = [
        'label' => 'Deduplication',
        'value' => 'Disabled',
        'removeUrl' => buildFilterUrl(['dedupe' => 1, 'page' => null]),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>AIresearch Search</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body class="search-page">
<header class="search-header">
    <div class="search-header__inner">
        <a class="brand" href="<?= esc($homeUrl); ?>" aria-label="AIresearch home">AI<span>research</span></a>
        <form class="searchbox" method="get" action="<?= esc($searchUrl); ?>" role="search" aria-label="Search AIresearch">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5s4.5 2.01 4.5 4.5S11.99 14 9.5 14z"/></svg>
            <label class="sr-only" for="header-query">Search AIresearch</label>
            <input id="header-query" type="search" name="q" value="<?= esc($query); ?>" placeholder="Search AIresearch" autofocus />
            <?= hiddenInputs([
                'since' => $since !== 'all' ? $since : null,
                'lang' => $lang,
                'source' => $sourceFilters,
                'dedupe' => $dedupe,
            ]); ?>
        </form>
    </div>
</header>
<main class="search-main">
    <div class="search-main__primary">
        <div class="search-summary">
            <p class="search-summary__status"><?= esc($status); ?></p>
            <?php if ($metricCards !== []): ?>
                <div class="search-summary__metrics">
                    <?php foreach ($metricCards as $card): ?>
                        <div class="metric-pill">
                            <span class="metric-pill__value"><?= esc($card['value']); ?></span>
                            <span class="metric-pill__label"><?= esc($card['label']); ?></span>
                            <?php if (($card['hint'] ?? '') !== ''): ?>
                                <span class="metric-pill__hint"><?= esc($card['hint']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($activeFilters !== []): ?>
            <div class="active-filters">
                <span class="active-filters__label">Active filters</span>
                <div class="active-filters__chips">
                    <?php foreach ($activeFilters as $chip): ?>
                        <a class="filter-chip" href="<?= esc($chip['removeUrl']); ?>">
                            <span class="filter-chip__label"><?= esc($chip['label']); ?></span>
                            <span class="filter-chip__value"><?= esc($chip['value']); ?></span>
                            <span class="filter-chip__remove" aria-hidden="true">&times;</span>
                            <span class="sr-only">Remove <?= esc($chip['label']); ?> filter</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="search-error" role="alert"><?= esc($error); ?></div>
        <?php endif; ?>
        <?php if ($results): ?>
            <ol class="results-list">
                <?php foreach ($results as $result): ?>
                    <?php
                        $url = isset($result['url']) ? (string) $result['url'] : '';
                        $urlParts = $url !== '' ? parse_url($url) : null;
                        $displayHost = '';
                        if (is_array($urlParts) && isset($urlParts['host'])) {
                            $displayHost = (string) $urlParts['host'];
                        } elseif (!empty($result['source'])) {
                            $displayHost = (string) $result['source'];
                        }
                        $timeLabel = isset($result['time']) ? (string) $result['time'] : '';
                        $entities = [];
                        if (isset($result['entities']) && is_array($result['entities'])) {
                            foreach ($result['entities'] as $entity) {
                                $label = trim((string) $entity);
                                if ($label === '') {
                                    continue;
                                }
                                $entities[] = $label;
                            }
                        }
                        $entities = array_slice(array_unique($entities), 0, 3);
                        $collapsedCount = isset($result['collapsed']) ? (int) $result['collapsed'] : 0;
                        $languageTag = isset($result['lang']) ? strtoupper((string) $result['lang']) : '';
                    ?>
                    <li class="result">
                        <?php if ($displayHost !== '' || $timeLabel !== ''): ?>
                            <div class="result__meta-line">
                                <?php if ($displayHost !== ''): ?>
                                    <span class="result__url"><?= esc($displayHost); ?></span>
                                <?php endif; ?>
                                <?php if ($displayHost !== '' && $timeLabel !== ''): ?>
                                    <span class="result__dot" aria-hidden="true">·</span>
                                <?php endif; ?>
                                <?php if ($timeLabel !== ''): ?>
                                    <span class="result__meta"><?= esc($timeLabel); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <h2 class="result__title">
                            <?php if ($url !== ''): ?>
                                <a href="<?= esc($url); ?>" target="_blank" rel="noopener noreferrer"><?= $result['title']; ?></a>
                            <?php else: ?>
                                <?= $result['title']; ?>
                            <?php endif; ?>
                        </h2>
                        <?php if (!empty($result['snippet'])): ?>
                            <p class="result__snippet"><?= $result['snippet']; ?></p>
                        <?php endif; ?>
                        <?php
                            $tagItems = [];
                            foreach ($entities as $entity) {
                                $tagItems[] = [
                                    'class' => 'entity',
                                    'label' => $entity,
                                    'title' => 'Entity match',
                                ];
                            }
                            if ($languageTag !== '') {
                                $tagItems[] = [
                                    'class' => 'lang',
                                    'label' => $languageTag,
                                    'title' => 'Detected language',
                                ];
                            }
                            if ($collapsedCount > 0) {
                                $tagItems[] = [
                                    'class' => 'cluster',
                                    'label' => '+' . formatNumberValue($collapsedCount),
                                    'title' => 'Collapsed similar results',
                                ];
                            }
                        ?>
                        <?php if ($tagItems !== []): ?>
                            <ul class="result__tags">
                                <?php foreach ($tagItems as $tag): ?>
                                    <li class="result__tag result__tag--<?= esc($tag['class']); ?>" title="<?= esc($tag['title']); ?>"><?= esc($tag['label']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($url !== ''): ?>
                            <div class="result__actions">
                                <a class="result__action" href="<?= esc($url); ?>" target="_blank" rel="noopener">Open source</a>
                                <button
                                    type="button"
                                    class="result__action result__action--secondary result__copy-button"
                                    data-url="<?= esc($url); ?>"
                                    data-label-default="Copy link"
                                    data-label-success="Copied!"
                                >
                                    Copy link
                                </button>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <div class="empty-state">
                <h2>No results yet</h2>
                <p>Run a query or adjust filters to surface indexed evidence.</p>
            </div>
        <?php endif; ?>
        <?php if ($total > PAGE_SIZE): ?>
            <nav class="results__pagination" aria-label="Pagination">
                <?php $totalPages = (int) ceil($total / PAGE_SIZE); ?>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php $isActivePage = $p === $page; ?>
                    <a class="pagination__link<?= $isActivePage ? ' pagination__link--active' : ''; ?>" href="<?= esc(buildFilterUrl(['page' => $p])); ?>"><?= $p; ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </div>
</main>
<footer class="search-footer">© <?= date('Y'); ?> AIresearch · Search insights engine</footer>
<script>
    (function () {
        const buttons = Array.from(document.querySelectorAll('.result__copy-button'));
        if (buttons.length === 0) {
            return;
        }

        const clipboardAvailable = navigator.clipboard && typeof navigator.clipboard.writeText === 'function';

        buttons.forEach((button) => {
            const defaultLabel = button.dataset.labelDefault || button.textContent || 'Copy link';
            const successLabel = button.dataset.labelSuccess || 'Copied!';

            if (!clipboardAvailable) {
                button.disabled = true;
                button.title = 'Clipboard access unavailable';
                button.textContent = defaultLabel;
                return;
            }

            button.addEventListener('click', () => {
                const url = button.dataset.url;
                if (!url) {
                    return;
                }

                navigator.clipboard.writeText(url).then(() => {
                    button.classList.add('result__action--success');
                    button.textContent = successLabel;
                    setTimeout(() => {
                        button.classList.remove('result__action--success');
                        button.textContent = defaultLabel;
                    }, 2000);
                }).catch(() => {
                    button.classList.add('result__action--error');
                    button.textContent = 'Copy failed';
                    setTimeout(() => {
                        button.classList.remove('result__action--error');
                        button.textContent = defaultLabel;
                    }, 2000);
                });
            });
        });
    }());
</script>
</body>
</html>
<?php
function buildFilterUrl(array $overrides): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return $query === '' ? $base : $base . '?' . $query;
}

function hiddenInputs(array $data): string
{
    $html = '';
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                $html .= '<input type="hidden" name="' . htmlspecialchars($key . '[]', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
            }
        } elseif ($value !== '' && $value !== null) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
    }
    return $html;
}
