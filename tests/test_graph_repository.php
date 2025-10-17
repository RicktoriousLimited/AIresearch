<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Extraction\ExtractionResult;
use App\KnowledgeGraph\GraphRepository;

ini_set('assert.exception', '1');

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be true.');
    }
}

$directory = sys_get_temp_dir() . '/graph_repo_' . uniqid('', true);
if (!mkdir($directory) && !is_dir($directory)) {
    throw new RuntimeException('Failed to create temporary directory.');
}

$path = $directory . '/snapshot.json';
$repository = new GraphRepository($path);
$result = new ExtractionResult([], [], [], [], [], [], [], [], []);

$repository->save($result, []);
$payload = $repository->load();

assertTrue(is_array($payload['graph']), 'Graph payload should be stored.');
assertTrue(is_file($path), 'Snapshot file should exist.');

$previousContents = file_get_contents($path);
assertTrue($previousContents !== false, 'Should read previous snapshot.');

sleep(1);

$repository->save($result, []);
$newContents = file_get_contents($path);
assertTrue($newContents !== false, 'Should read refreshed snapshot.');

$firstDecoded = json_decode($previousContents, true);
$secondDecoded = json_decode($newContents, true);

assertTrue(is_array($firstDecoded), 'First snapshot should decode.');
assertTrue(is_array($secondDecoded), 'Second snapshot should decode.');
assertTrue(($firstDecoded['updated_at'] ?? null) !== ($secondDecoded['updated_at'] ?? null), 'Timestamps should differ after rewrite.');

@unlink($path);
rmdir($directory);
