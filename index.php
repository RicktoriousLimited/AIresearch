<?php
require __DIR__ . '/src/SemanticEngine.php';

$engine = new SemanticEngine();

$biographies = [
    <<<BIO
    Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith. Alice lives in Birmingham.
    BIO,
    <<<BIO
    Ricktorious Limited is a technology company. Ricktorious Limited aka Ricktorious Ltd.
    BIO,
];

$extractedTriples = [];
foreach ($biographies as $bio) {
    $triples = $engine->extractRelations($bio);
    if ($triples !== []) {
        array_push($extractedTriples, ...$triples);
    }
}

$engine->addTriple('Alice Smith', 'works_at', 'Ricktorious Limited');
$engine->addTriple('Alice Smith', 'lives_at', 'Birmingham, United Kingdom');
$engine->addSynonym('Birmingham', 'City of Birmingham');

$insights = [
    'alice_is_data_scientist' => $engine->queryIsA('Alice Smith', 'Senior Data Scientist'),
    'alice_synonyms' => $engine->querySynonyms('Alice Smith'),
    'company_synonyms' => $engine->querySynonyms('Ricktorious Limited'),
    'city_synonyms' => $engine->querySynonyms('Birmingham'),
];

$graphTriples = $engine->iterTriples();
$synonymPairs = $engine->iterSynonyms();

function renderHeader(string $title): void {
    echo str_repeat('=', 80) . PHP_EOL;
    echo $title . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
}

function renderTable(array $rows): void {
    foreach ($rows as $row) {
        if (is_array($row)) {
            echo '- ' . implode(' | ', array_map('strval', $row)) . PHP_EOL;
        } else {
            echo '- ' . (string) $row . PHP_EOL;
        }
    }
}

renderHeader('Extracted triples from biographies');
renderTable($extractedTriples);

echo PHP_EOL;
renderHeader('Direct insights from the knowledge graph');
foreach ($insights as $label => $value) {
    echo sprintf('- %s: %s', $label, is_bool($value) ? ($value ? 'true' : 'false') : json_encode($value, JSON_THROW_ON_ERROR)) . PHP_EOL;
}

echo PHP_EOL;
renderHeader('All triples currently stored');
renderTable($graphTriples);

echo PHP_EOL;
renderHeader('Synonym clusters');
foreach ($synonymPairs as [$entity, $synonyms]) {
    echo '- ' . $entity . ' => ' . implode(', ', $synonyms) . PHP_EOL;
}
