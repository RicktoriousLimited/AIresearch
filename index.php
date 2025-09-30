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
    <<<BIO
    Bob Hernandez is an AI Research Engineer. Bob Hernandez also known as Robert J. Hernandez.
    BIO,
    <<<BIO
    Carla Rossi is a Principal Investigator. Carla leads the Horizon Lab. Horizon Lab aka Horizon Research Laboratory.
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
$engine->addTriple('Alice Smith', 'collaborates_with', 'Bob Hernandez');
$engine->addTriple('Bob Hernandez', 'works_at', 'Horizon Lab');
$engine->addTriple('Carla Rossi', 'leads', 'Horizon Lab');
$engine->addTriple('Horizon Lab', 'focuses_on', 'Responsible AI Research');
$engine->addTriple('Horizon Lab', 'located_in', 'London, United Kingdom');

$engine->addSynonym('Birmingham', 'City of Birmingham');
$engine->addSynonym('Responsible AI Research', 'RAI Research');
$engine->addSynonym('London, United Kingdom', 'City of London');
$engine->addSynonym('Horizon Lab', 'Horizon Research Laboratory');

$engine->addTriple('Alice Smith', 'isa', 'Senior Data Scientist');
$engine->addTriple('Bob Hernandez', 'isa', 'AI Research Engineer');
$engine->addTriple('Carla Rossi', 'isa', 'Principal Investigator');

$insights = [
    'alice_is_data_scientist' => $engine->queryIsA('Alice Smith', 'Senior Data Scientist'),
    'alice_synonyms' => $engine->querySynonyms('Alice Smith'),
    'company_synonyms' => $engine->querySynonyms('Ricktorious Limited'),
    'city_synonyms' => $engine->querySynonyms('Birmingham'),
    'horizon_lab_synonyms' => $engine->querySynonyms('Horizon Lab'),
    'rai_synonyms' => $engine->querySynonyms('Responsible AI Research'),
];

$graphTriples = $engine->iterTriples();
$synonymPairs = $engine->iterSynonyms();
$isaTriples = $engine->iterTriples('isa');

function groupTriplesByRelation(array $triples): array {
    $grouped = [];
    foreach ($triples as [$subject, $relation, $object]) {
        $grouped[$relation][] = [$subject, $object];
    }

    ksort($grouped);
    return $grouped;
}

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

function renderGroupedTriples(array $grouped): void {
    foreach ($grouped as $relation => $pairs) {
        echo strtoupper($relation) . PHP_EOL;
        foreach ($pairs as [$subject, $object]) {
            echo sprintf("  • %s -> %s", $subject, $object) . PHP_EOL;
        }
        echo PHP_EOL;
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
renderHeader('Triples grouped by relation');
renderGroupedTriples(groupTriplesByRelation($graphTriples));

echo PHP_EOL;
renderHeader('All known isa relationships');
renderTable($isaTriples);

echo PHP_EOL;
renderHeader('Synonym clusters');
foreach ($synonymPairs as [$entity, $synonyms]) {
    echo '- ' . $entity . ' => ' . implode(', ', $synonyms) . PHP_EOL;
}
