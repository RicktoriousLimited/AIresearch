<?php
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>News Intelligence Search</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
<header>
    <div class="container">
        <form class="search-bar" method="get" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input type="text" name="q" placeholder="Search the news" value="<?php echo htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
            <button type="submit">Search</button>
        </form>
        <div class="filters">
            <?php foreach ($timeFilters as $key => $label): ?>
                <?php $classes = ['pill']; if ($since === $key) $classes[] = 'active'; ?>
                <a class="<?php echo implode(' ', $classes); ?>" href="<?php echo buildFilterUrl(['since' => $key, 'page' => 1]); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
            <?php endforeach; ?>
            <?php $dedupeTarget = $dedupe ? 0 : 1; ?>
            <a class="pill <?php echo $dedupe ? 'active' : ''; ?>" href="<?php echo buildFilterUrl(['dedupe' => $dedupeTarget, 'page' => 1]); ?>">
                <?php echo $dedupe ? 'Dedupe on' : 'Dedupe off'; ?>
            </a>
            <?php if (!empty($facets['source'])): ?>
                <?php $topSources = array_slice(array_keys($facets['source']), 0, 5); ?>
                <?php foreach ($topSources as $src): ?>
                    <?php $active = in_array($src, $sourceFilters, true); ?>
                    <?php $newSources = $sourceFilters; ?>
                    <?php if ($active) { $newSources = array_values(array_filter($newSources, fn($s) => $s !== $src)); } else { $newSources[] = $src; } ?>
                    <?php $href = buildFilterUrl(['source' => $newSources ? $newSources : null, 'page' => 1]); ?>
                    <a class="pill <?php echo $active ? 'active' : ''; ?>" href="<?php echo $href; ?>"><?php echo htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($langOptions)): ?>
                <form class="pill" method="get" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <?php echo hiddenInputs(['q' => $query, 'page' => 1, 'since' => $since, 'dedupe' => $dedupe, 'source' => $sourceFilters]); ?>
                    <label>
                        <span>Language</span>
                        <select name="lang" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($langOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo strtolower($lang) === strtolower($option) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<main>
    <div class="container">
        <?php if ($error): ?>
            <div class="empty-state"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="results-meta">
            <strong><?php echo $total; ?></strong> results · <?php echo $latency; ?> ms · Index <?php echo htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>

        <?php if ($facets['source'] || $facets['lang']): ?>
            <div class="facets">
                <?php if ($facets['source']): ?>
                    <div>
                        <h3>Sources</h3>
                        <ul>
                            <?php foreach ($facets['source'] as $source => $count): ?>
                                <li>
                                    <span><?php echo htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <span><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($facets['lang']): ?>
                    <div>
                        <h3>Languages</h3>
                        <ul>
                            <?php foreach ($facets['lang'] as $language => $count): ?>
                                <li>
                                    <span><?php echo htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <span><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div>
                    <h3>Freshness</h3>
                    <ul>
                        <li><span>24h</span><span><?php echo $facets['date']['last24h'] ?? 0; ?></span></li>
                        <li><span>7d</span><span><?php echo $facets['date']['last7d'] ?? 0; ?></span></li>
                        <li><span>30d</span><span><?php echo $facets['date']['last30d'] ?? 0; ?></span></li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($results): ?>
            <div class="results-grid">
                <?php foreach ($results as $result): ?>
                    <article class="result-card">
                        <h2><a href="<?php echo htmlspecialchars($result['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $result['title']; ?></a></h2>
                        <div class="result-meta">
                            <span><?php echo htmlspecialchars($result['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <span><?php echo htmlspecialchars($result['time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <span><?php echo strtoupper(htmlspecialchars($result['lang'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></span>
                            <?php if (($result['collapsed'] ?? 0) > 0): ?>
                                <span class="badge">+<?php echo (int) $result['collapsed']; ?> more sources</span>
                            <?php endif; ?>
                        </div>
                        <p class="lede"><?php echo $result['lede']; ?></p>
                        <?php if (!empty($result['entities'])): ?>
                            <div class="entity-chips">
                                <?php foreach ($result['entities'] as $entity): ?>
                                    <span><?php echo htmlspecialchars($entity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                No results yet. Try searching for "markets" or "energy".
            </div>
        <?php endif; ?>

        <?php if ($total > PAGE_SIZE): ?>
            <nav class="pagination">
                <?php for ($p = 1; $p <= ceil($total / PAGE_SIZE); $p++): ?>
                    <?php $classes = $p === $page ? 'active' : ''; ?>
                    <a class="<?php echo $classes; ?>" href="<?php echo buildFilterUrl(['page' => $p]); ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </div>
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
        } else {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
    }
    return $html;
}

