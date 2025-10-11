<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];
$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$archiveReadme = PathResolver::url($assetBase, 'archive/README.md');
$homePath = PathResolver::url($assetBase, 'index.php');

http_response_code(410);
header('Content-Type: text/html; charset=utf-8');

$archivedDate = (new DateTimeImmutable())->format('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch · Commerce demo archived</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
    <style>
        body.archive-body {
            background: linear-gradient(155deg, rgba(15, 23, 42, 0.86), rgba(30, 64, 175, 0.9));
            color: #f8fafc;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .archive-panel {
            max-width: 560px;
            background: rgba(15, 23, 42, 0.78);
            border-radius: 28px;
            padding: 2.6rem 2.4rem;
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.35);
            display: grid;
            gap: 1.25rem;
        }

        .archive-panel h1 {
            margin: 0;
            font-size: clamp(2.1rem, 4vw, 2.6rem);
            letter-spacing: -0.02em;
        }

        .archive-panel p {
            margin: 0;
            color: rgba(226, 232, 240, 0.85);
            line-height: 1.7;
        }

        .archive-panel a {
            color: #93c5fd;
        }

        .archive-actions {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            margin-top: 0.5rem;
        }

        .archive-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.85rem 1.4rem;
            border-radius: 999px;
            font-weight: 600;
            text-decoration: none;
        }

        .archive-actions a.primary {
            background: #2563eb;
            color: #f8fafc;
            box-shadow: 0 18px 45px rgba(37, 99, 235, 0.28);
        }

        .archive-actions a.secondary {
            border: 1px solid rgba(148, 163, 184, 0.35);
            color: rgba(226, 232, 240, 0.9);
            background: transparent;
        }

        .archive-meta {
            font-size: 0.85rem;
            color: rgba(148, 163, 184, 0.75);
        }

        @media (max-width: 520px) {
            .archive-panel {
                padding: 2.2rem 1.8rem;
            }

            .archive-actions {
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body class="archive-body">
    <main class="archive-panel">
        <h1>Commerce demo archived</h1>
        <p>The experimental Ricktorious storefront has been retired while AIresearch focuses exclusively on evidence-led research workflows.</p>
        <p>Historical assets now live in the <a href="<?= esc($archiveReadme); ?>">archive directory</a>. Reach out if you need assistance retrieving a specific component.</p>
        <div class="archive-actions">
            <a class="primary" href="<?= esc($homePath); ?>">Return to AIresearch</a>
            <a class="secondary" href="mailto:support@airesearch.local">Contact the research team</a>
        </div>
        <p class="archive-meta">HTTP 410 · Archived on <?= esc($archivedDate); ?></p>
    </main>
</body>
</html>
