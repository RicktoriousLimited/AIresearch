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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>AIresearch Search</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body class="search-page">
<main class="search-main" id="main">
    <div class="search-top">
        <a class="search-top__logo" href="<?= esc($homeUrl); ?>" aria-label="AIresearch home">AIresearch</a>
        <form class="search-top__form" method="get" action="<?= esc($searchUrl); ?>" role="search">
            <label class="visually-hidden" for="header-query">Search AIresearch</label>
            <div class="search-input">
                <span class="search-input__icon" aria-hidden="true"></span>
                <input id="header-query" type="search" name="q" value="<?= esc($query); ?>" placeholder="Search AIresearch" autofocus />
                <?php if ($query !== ''): ?>
                    <a class="search-input__clear" href="<?= esc($searchUrl); ?>" aria-label="Clear search"></a>
                <?php endif; ?>
                <button class="search-input__submit" type="submit" aria-label="Search"></button>
            </div>
        </form>
    </div>
    <?php if ($error): ?>
        <p class="search-error" role="alert"><?= esc($error); ?></p>
    <?php endif; ?>
    <section class="results" aria-label="Search results">
        <?php if ($results): ?>
            <ol class="results__list">
                <?php foreach ($results as $result): ?>
                    <?php $urlParts = parse_url($result['url']); ?>
                    <?php $displayHost = $urlParts['host'] ?? $result['url']; ?>
                    <li class="result">
                        <div class="result__path">
                            <span class="result__host"><?= esc($displayHost); ?></span>
                            <?php if (!empty($result['time'])): ?>
                                <span class="result__dot" aria-hidden="true">·</span>
                                <span><?= esc($result['time']); ?></span>
                            <?php endif; ?>
                        </div>
                        <h2 class="result__title">
                            <a href="<?= esc($result['url']); ?>" target="_blank" rel="noopener noreferrer"><?= $result['title']; ?></a>
                        </h2>
                        <?php if (!empty($result['lede'])): ?>
                            <p class="result__snippet"><?= $result['lede']; ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="results__empty">Start typing to explore the index.</p>
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
    </section>
</main>
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
        } elseif ($value !== '' && $value !== null) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
    }
    return $html;
}
