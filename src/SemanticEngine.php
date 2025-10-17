<?php

require_once __DIR__ . '/EnglishLexicon.php';

/**
 * Core semantic engine for knowledge graph extraction.
 */
class SemanticEngine
{
    /** @var EnglishLexicon */
    private EnglishLexicon $englishLexicon;

    /** @var array<string, array<string, array<string, true>>> */
    private array $graph = [];

    /** @var array<string, array<string, true>> */
    private array $synonyms = [];

    /** @var array<string, array<string, float>> */
    private array $relatedWeights = [];

    /** @var array<string, float> */
    private array $relatedFrequency = [];

    /**
     * Entity-centric aggregation of relations for lightweight profiling.
     *
     * @var array<string, array{as_subject: array<string, int>, as_object: array<string, int>}>
     */
    private array $entityProfiles = [];

    /**
     * Aggregated document ranking signals used to prioritise cross references.
     *
     * @var array{uniqueness: float, freshness: float, quality: float, consistency: float}
     */
    private array $documentSignalSums = [
        'uniqueness' => 0.0,
        'freshness' => 0.0,
        'quality' => 0.0,
        'consistency' => 0.0,
    ];

    private int $documentSignalCount = 0;

    /** @var array<string, true> */
    private array $verbLexicon = [];

    /** @var array<string, true> */
    private static array $entityStopwords = [
        'a' => true,
        'an' => true,
        'the' => true,
    ];

    /** @var array<string, true> */
    private static array $clauseOpeners = [
        'because' => true,
        'when' => true,
        'while' => true,
        'if' => true,
        'that' => true,
        'whether' => true,
        'what' => true,
        'which' => true,
        'who' => true,
    ];

    /** @var array<string, true> */
    private static array $nonActionVerbs = [
        'am' => true,
        'are' => true,
        'be' => true,
        'been' => true,
        'being' => true,
        'do' => true,
        'does' => true,
        'did' => true,
        'had' => true,
        'has' => true,
        'have' => true,
        'is' => true,
        'was' => true,
        'were' => true,
    ];

    /** @var array<string, true> */
    private static array $determinants = [
        'a' => true,
        'an' => true,
        'the' => true,
        'this' => true,
        'that' => true,
        'these' => true,
        'those' => true,
        'my' => true,
        'your' => true,
        'his' => true,
        'her' => true,
        'its' => true,
        'our' => true,
        'their' => true,
        'to' => true,
    ];

    /** @var array<int, string> */
    private static array $nounSuffixes = [
        'tion',
        'sion',
        'ment',
        'ness',
        'ism',
        'ity',
        'ics',
        'ship',
        'hood',
        'ance',
        'ence',
        'ology',
        'logy',
        'ware',
        'ous',
        'ium',
        'ums',
        'ms',
    ];

    public function __construct(?EnglishLexicon $englishLexicon = null)
    {
        $this->englishLexicon = $englishLexicon ?? EnglishLexicon::loadDefault();
    }

    /**
     * Register document-level ranking signals to influence cross-reference eligibility.
     *
     * @param array{uniqueness?: float, freshness?: float, quality?: float, consistency?: float} $signals
     */
    public function registerDocumentSignals(array $signals): void
    {
        $this->documentSignalCount++;

        foreach (['uniqueness', 'freshness', 'quality', 'consistency'] as $key) {
            $value = $signals[$key] ?? 0.0;
            if (!is_numeric($value)) {
                $value = 0.0;
            }
            $this->documentSignalSums[$key] += $this->clampScore((float) $value);
        }
    }

    /**
     * Normalise a token.
     */
    public function norm(string $token): string
    {
        $token = strtolower(trim($token));
        $token = preg_replace('/[^0-9a-z-]+/i', '', $token);
        return $token ?? '';
    }

    /**
     * Normalise an entity while preserving multi-word layout.
     */
    public function normalizeEntity(?string $entity): string
    {
        if ($entity === null) {
            return '';
        }

        $entity = strtolower(trim($entity));
        if ($entity === '') {
            return '';
        }

        $pieces = [];
        $parts = preg_split('/(\s+|-+)/u', $entity, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $parts = [];
        }

        $lastPiece = null;
        foreach ($parts as $part) {
            if (ctype_space($part)) {
                if ($lastPiece !== ' ') {
                    $pieces[] = ' ';
                    $lastPiece = ' ';
                }
                continue;
            }

            if (preg_match('/^-+$/', $part)) {
                if ($lastPiece !== '-') {
                    $pieces[] = '-';
                    $lastPiece = '-';
                }
                continue;
            }

            $token = $this->norm($part);
            if ($token === '') {
                continue;
            }

            if (empty($pieces) && isset(self::$entityStopwords[$token])) {
                continue;
            }

            $pieces[] = $token;
            $lastPiece = $token;
        }

        $normalized = trim(implode('', $pieces), " -");
        return $normalized;
    }

    /**
     * Add a triple to the knowledge graph.
     */
    public function addTriple(string $subject, string $relation, string $object): void
    {
        $subjectKey = $this->normalizeEntity($subject);
        $objectKey = $this->normalizeEntity($object);
        $relationKey = $this->norm($relation);

        if ($subjectKey === '' || $objectKey === '' || $relationKey === '') {
            return;
        }

        if (!isset($this->graph[$relationKey])) {
            $this->graph[$relationKey] = [];
        }
        if (!isset($this->graph[$relationKey][$subjectKey])) {
            $this->graph[$relationKey][$subjectKey] = [];
        }

        $this->graph[$relationKey][$subjectKey][$objectKey] = true;

        $this->ensureEntityProfile($subjectKey);
        $this->ensureEntityProfile($objectKey);
        $this->entityProfiles[$subjectKey]['as_subject'][$relationKey] = ($this->entityProfiles[$subjectKey]['as_subject'][$relationKey] ?? 0) + 1;
        $this->entityProfiles[$objectKey]['as_object'][$relationKey] = ($this->entityProfiles[$objectKey]['as_object'][$relationKey] ?? 0) + 1;

        if (strpos($relationKey, 'action-') === 0) {
            $verb = substr($relationKey, 7);
            if ($verb !== '') {
                $this->verbLexicon[$verb] = true;
            }
        }
    }

    /**
     * Register synonyms between two entities.
     */
    public function addSynonym(string $left, string $right): void
    {
        $leftKey = $this->normalizeEntity($left);
        $rightKey = $this->normalizeEntity($right);
        if ($leftKey === '' || $rightKey === '' || $leftKey === $rightKey) {
            return;
        }

        if (!isset($this->synonyms[$leftKey])) {
            $this->synonyms[$leftKey] = [];
        }
        if (!isset($this->synonyms[$rightKey])) {
            $this->synonyms[$rightKey] = [];
        }

        $this->synonyms[$leftKey][$rightKey] = true;
        $this->synonyms[$rightKey][$leftKey] = true;

        $this->addTriple($left, 'synonym', $right);
        $this->addTriple($right, 'synonym', $left);
    }

    /**
     * Determine if subject is-a object.
     */
    public function queryIsA(string $subject, string $object): bool
    {
        $subjectKey = $this->normalizeEntity($subject);
        $objectKey = $this->normalizeEntity($object);
        if ($subjectKey === '' || $objectKey === '') {
            return false;
        }

        if (!isset($this->graph['isa'][$subjectKey])) {
            return false;
        }

        return isset($this->graph['isa'][$subjectKey][$objectKey]);
    }

    /**
     * Retrieve synonyms for an entity.
     *
     * @return array<int, string>
     */
    public function querySynonyms(string $entity): array
    {
        $key = $this->normalizeEntity($entity);
        if ($key === '' || !isset($this->synonyms[$key])) {
            return [];
        }

        return array_keys($this->synonyms[$key]);
    }

    /**
     * Register keyword co-occurrence statistics to surface related entities.
     *
     * @param array<int, array{token?: mixed, count?: mixed}> $keywords
     */
    public function registerKeywordCooccurrence(array $keywords): void
    {
        if ($keywords === []) {
            return;
        }

        $counts = [];
        foreach ($keywords as $row) {
            if (!is_array($row)) {
                continue;
            }

            $token = $this->normalizeEntity((string) ($row['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $count = $row['count'] ?? 1;
            if (!is_numeric($count)) {
                $count = 1;
            }

            $counts[$token] = ($counts[$token] ?? 0.0) + max(1.0, (float) $count);
        }

        if ($counts === []) {
            return;
        }

        foreach ($counts as $token => $count) {
            $this->relatedFrequency[$token] = ($this->relatedFrequency[$token] ?? 0.0) + $count;
        }

        $tokens = array_keys($counts);
        $total = count($tokens);
        if ($total < 2) {
            return;
        }

        for ($i = 0; $i < $total; $i++) {
            $left = $tokens[$i];
            $leftCount = $counts[$left];

            for ($j = $i + 1; $j < $total; $j++) {
                $right = $tokens[$j];
                $rightCount = $counts[$right];

                $weight = sqrt($leftCount * $rightCount);
                if ($weight <= 0.0) {
                    continue;
                }

                if (!isset($this->relatedWeights[$left])) {
                    $this->relatedWeights[$left] = [];
                }
                if (!isset($this->relatedWeights[$right])) {
                    $this->relatedWeights[$right] = [];
                }

                $this->relatedWeights[$left][$right] = ($this->relatedWeights[$left][$right] ?? 0.0) + $weight;
                $this->relatedWeights[$right][$left] = ($this->relatedWeights[$right][$left] ?? 0.0) + $weight;
            }
        }
    }

    /**
     * Extract relation triples from text.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public function extractRelations(string $text): array
    {
        $triples = [];
        if (trim($text) === '') {
            return $triples;
        }

        $sentences = preg_split('/[.!?]+/', $text);
        if ($sentences === false) {
            $sentences = [];
        }

        $relationPatterns = [
            [
                'regex' => '/^(?P<subject>.+?)\s+works\s+(?:at|for)\s+(?P<object>.+)$/iu',
                'relation' => 'works_at',
            ],
            [
                'regex' => '/^(?P<subject>.+?)\s+lives\s+(?:in|at)\s+(?P<object>.+)$/iu',
                'relation' => 'lives_in',
            ],
            [
                'regex' => '/^(?P<subject>.+?)\s+(?:leads|heads)\s+(?P<object>.+)$/iu',
                'relation' => 'leads',
            ],
            [
                'regex' => '/^(?P<subject>.+?)\s+focuses\s+on\s+(?P<object>.+)$/iu',
                'relation' => 'focuses_on',
            ],
            [
                'regex' => '/^(?P<subject>.+?)\s+located\s+(?:in|at)\s+(?P<object>.+)$/iu',
                'relation' => 'located_in',
            ],
            [
                'regex' => '/^(?P<subject>.+?)\s+collaborates\s+with\s+(?P<object>.+)$/iu',
                'relation' => 'collaborates_with',
            ],
        ];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $aliasMatches = [];
            $aliasResult = preg_match_all('/(?P<entity>[\p{L}0-9][^()]{1,}?)\s*\((?P<alias>[^()]+)\)/u', $sentence, $aliasMatches, PREG_SET_ORDER);
            if ($aliasResult !== false && $aliasResult > 0) {
                foreach ($aliasMatches as $aliasMatch) {
                    $entityRaw = trim((string) ($aliasMatch['entity'] ?? ''));
                    $aliasRaw = trim((string) ($aliasMatch['alias'] ?? ''));
                    if ($entityRaw === '' || $aliasRaw === '') {
                        continue;
                    }

                    if (preg_match('/[a-z]/i', $aliasRaw) !== 1) {
                        continue;
                    }

                    $entity = $this->normalizeEntity($entityRaw);
                    $alias = $this->normalizeEntity($aliasRaw);
                    if ($entity === '' || $alias === '' || $entity === $alias) {
                        continue;
                    }

                    $this->addSynonym($entityRaw, $aliasRaw);
                    $triples[] = [$entity, 'synonym', $alias];
                }
            }

            if (preg_match('/^(?P<subj>.+?)\s+is\s+(?:an?\s+)?(?P<obj>[\w\s\-]+)$/iu', $sentence, $matches)) {
                $subjRaw = $matches['subj'];
                $objRaw = $matches['obj'];
                $this->addTriple($subjRaw, 'isa', $objRaw);
                $subj = $this->normalizeEntity($subjRaw);
                $obj = $this->normalizeEntity($objRaw);
                if ($subj !== '' && $obj !== '') {
                    $triples[] = [$subj, 'isa', $obj];
                }
                continue;
            }

            if (preg_match('/^(?P<left>.+?)\s+(aka|also known as|known as|nicknamed|alias(?:\s+of)?|goes by|synonym of)\s+(?P<right>.+)$/iu', $sentence, $matches)) {
                $leftRaw = $matches['left'];
                $rightRaw = $matches['right'];
                $this->addSynonym($leftRaw, $rightRaw);
                $left = $this->normalizeEntity($leftRaw);
                $right = $this->normalizeEntity($rightRaw);
                if ($left !== '' && $right !== '') {
                    $triples[] = [$left, 'synonym', $right];
                }
                continue;
            }

            if (preg_match('/^(?P<label>.+?)(?:\s*:\s*|\s+[\-\x{2013}\x{2014}]\s+)(?P<desc>.+)$/u', $sentence, $matches)) {
                $labelRaw = trim($matches['label']);
                $descriptionRaw = trim($matches['desc']);
                if ($labelRaw !== '' && $descriptionRaw !== '' && $this->containsAlpha($labelRaw) && $this->containsAlpha($descriptionRaw)) {
                    $this->addTriple($labelRaw, 'tagline', $descriptionRaw);
                    $label = $this->normalizeEntity($labelRaw);
                    $description = $this->normalizeEntity($descriptionRaw);
                    if ($label !== '' && $description !== '') {
                        $triples[] = [$label, 'tagline', $description];
                    }
                    continue;
                }
            }

            foreach ($relationPatterns as $pattern) {
                if (!preg_match($pattern['regex'], $sentence, $matches)) {
                    continue;
                }

                $subjectRaw = $matches['subject'];
                if ($pattern['relation'] === 'works_at') {
                    $normalizedSubject = $this->normalizeEntity($subjectRaw);
                    $tokens = preg_split('/\s+/u', $normalizedSubject);
                    if ($tokens === false) {
                        $tokens = [];
                    }

                    $complementisers = [
                        'what' => true,
                        'that' => true,
                        'whether' => true,
                        'because' => true,
                    ];

                    $hasComplementiser = false;
                    foreach ($tokens as $token) {
                        if ($token === '') {
                            continue;
                        }
                        if (isset($complementisers[$token])) {
                            $hasComplementiser = true;
                            break;
                        }
                    }

                    $tokenLimit = 6;
                    $tokenCount = 0;
                    foreach ($tokens as $token) {
                        if ($token !== '') {
                            $tokenCount++;
                        }
                    }

                    if ($normalizedSubject === '' || $hasComplementiser || $tokenCount > $tokenLimit) {
                        continue 2;
                    }
                }

                $objectRaw = $matches['object'];
                $this->addTriple($subjectRaw, $pattern['relation'], $objectRaw);
                $subject = $this->normalizeEntity($subjectRaw);
                $object = $this->normalizeEntity($objectRaw);
                if ($subject !== '' && $object !== '') {
                    $triples[] = [$subject, $this->norm($pattern['relation']), $object];
                }
                continue 2;
            }

            foreach ($this->extractActionTriples($sentence) as $triple) {
                $triples[] = $triple;
            }
        }

        return $triples;
    }

    /**
     * Iterate over stored triples.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public function iterTriples(?string $relation = null): array
    {
        $result = [];
        if ($relation !== null) {
            $relationKey = $this->norm($relation);
            if ($relationKey === '' || !isset($this->graph[$relationKey])) {
                return $result;
            }

            foreach ($this->graph[$relationKey] as $subject => $objects) {
                foreach ($objects as $object => $_) {
                    $result[] = [$subject, $relationKey, $object];
                }
            }
            return $result;
        }

        foreach ($this->graph as $relationKey => $subjects) {
            foreach ($subjects as $subject => $objects) {
                foreach ($objects as $object => $_) {
                    $result[] = [$subject, $relationKey, $object];
                }
            }
        }

        return $result;
    }

    /**
     * Iterate over stored synonyms.
     *
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    public function iterSynonyms(): array
    {
        $result = [];
        foreach ($this->synonyms as $key => $values) {
            $result[] = [$key, array_keys($values)];
        }
        return $result;
    }

    /**
     * @return array<string, array<int, array{entity: string, score: float}>>
     */
    public function getRelatedTerms(int $limit = 6, float $threshold = 0.2): array
    {
        $result = [];
        foreach ($this->relatedWeights as $entity => $neighbors) {
            if (!is_array($neighbors) || $neighbors === []) {
                continue;
            }

            if (!$this->isTrackedEntity($entity)) {
                continue;
            }

            $scored = [];
            foreach ($neighbors as $neighbor => $weight) {
                if (!is_string($neighbor) || $neighbor === '' || !$this->isTrackedEntity($neighbor)) {
                    continue;
                }

                $score = $this->scoreRelatedTerm($entity, $neighbor, $weight);
                if ($score < $threshold) {
                    continue;
                }

                $scored[] = [
                    'entity' => $neighbor,
                    'score' => round($this->clampScore($score), 4),
                ];
            }

            if ($scored === []) {
                continue;
            }

            usort(
                $scored,
                static function (array $left, array $right): int {
                    $scoreComparison = $right['score'] <=> $left['score'];
                    if ($scoreComparison !== 0) {
                        return $scoreComparison;
                    }

                    return $left['entity'] <=> $right['entity'];
                }
            );

            $result[$entity] = array_slice($scored, 0, max(1, $limit));
        }

        if ($result === []) {
            return [];
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<int, array{entity: string, related: array<int, array{entity: string, score: float}>}>
     */
    public function iterRelatedTerms(): array
    {
        $result = [];
        foreach ($this->getRelatedTerms() as $entity => $neighbors) {
            $result[] = [
                'entity' => $entity,
                'related' => $neighbors,
            ];
        }

        return $result;
    }

    /**
     * Export the engine state.
     *
     * @return array{
     *     graph: array<string, array<string, array<string, true>>>,
     *     synonyms: array<string, array<string, true>>,
     *     profiles: array<string, array{as_subject: array<string, int>, as_object: array<string, int>}>,
     *     verbs: array<int, string>,
     *     document_signals: array{count: int, sums: array{uniqueness: float, freshness: float, quality: float, consistency: float}},
     *     related: array{weights: array<string, array<string, float>>, frequency: array<string, float>}
     * }
     */
    public function toArray(): array
    {
        return [
            'graph' => $this->graph,
            'synonyms' => $this->synonyms,
            'profiles' => $this->entityProfiles,
            'verbs' => array_keys($this->verbLexicon),
            'document_signals' => [
                'count' => $this->documentSignalCount,
                'sums' => $this->documentSignalSums,
            ],
            'related' => [
                'weights' => $this->relatedWeights,
                'frequency' => $this->relatedFrequency,
            ],
        ];
    }

    /**
     * Restore an engine instance from exported state.
     *
     * @param array{graph?: mixed, synonyms?: mixed} $payload
     */
    public static function fromArray(array $payload): self
    {
        $engine = new self();

        if (isset($payload['graph']) && is_array($payload['graph'])) {
            foreach ($payload['graph'] as $relation => $subjects) {
                if (!is_string($relation) || $relation === '' || !is_array($subjects)) {
                    continue;
                }

                foreach ($subjects as $subject => $objects) {
                    if (!is_string($subject) || $subject === '' || !is_array($objects)) {
                        continue;
                    }

                    foreach ($objects as $object => $flag) {
                        if (!is_string($object) || $object === '' || $flag !== true) {
                            continue;
                        }

                        if (!isset($engine->graph[$relation])) {
                            $engine->graph[$relation] = [];
                        }
                        if (!isset($engine->graph[$relation][$subject])) {
                            $engine->graph[$relation][$subject] = [];
                        }

                        $engine->graph[$relation][$subject][$object] = true;
                    }
                }
            }
        }

        if (isset($payload['synonyms']) && is_array($payload['synonyms'])) {
            foreach ($payload['synonyms'] as $entity => $synonyms) {
                if (!is_string($entity) || $entity === '' || !is_array($synonyms)) {
                    continue;
                }

                foreach ($synonyms as $synonym => $flag) {
                    if (!is_string($synonym) || $synonym === '' || $flag !== true) {
                        continue;
                    }

                    if (!isset($engine->synonyms[$entity])) {
                        $engine->synonyms[$entity] = [];
                    }

                    $engine->synonyms[$entity][$synonym] = true;
                }
            }
        }

        if (isset($payload['related']) && is_array($payload['related'])) {
            $weights = $payload['related']['weights'] ?? [];
            if (is_array($weights)) {
                foreach ($weights as $entity => $neighbors) {
                    if (!is_string($entity) || $entity === '' || !is_array($neighbors)) {
                        continue;
                    }

                    foreach ($neighbors as $neighbor => $value) {
                        if (!is_string($neighbor) || $neighbor === '') {
                            continue;
                        }

                        $numeric = is_numeric($value) ? (float) $value : 0.0;
                        if ($numeric <= 0.0) {
                            continue;
                        }

                        if (!isset($engine->relatedWeights[$entity])) {
                            $engine->relatedWeights[$entity] = [];
                        }
                        $engine->relatedWeights[$entity][$neighbor] = $numeric;
                    }
                }
            }

            $frequency = $payload['related']['frequency'] ?? [];
            if (is_array($frequency)) {
                foreach ($frequency as $entity => $value) {
                    if (!is_string($entity) || $entity === '') {
                        continue;
                    }

                    if (!is_numeric($value)) {
                        continue;
                    }

                    $engine->relatedFrequency[$entity] = max(0.0, (float) $value);
                }
            }
        }

        if (isset($payload['profiles']) && is_array($payload['profiles'])) {
            foreach ($payload['profiles'] as $entity => $profile) {
                if (!is_string($entity) || $entity === '' || !is_array($profile)) {
                    continue;
                }

                $subject = [];
                $object = [];

                if (isset($profile['as_subject']) && is_array($profile['as_subject'])) {
                    foreach ($profile['as_subject'] as $relation => $count) {
                        if (!is_string($relation) || $relation === '' || !is_int($count)) {
                            continue;
                        }
                        $subject[$relation] = $count;
                    }
                }

                if (isset($profile['as_object']) && is_array($profile['as_object'])) {
                    foreach ($profile['as_object'] as $relation => $count) {
                        if (!is_string($relation) || $relation === '' || !is_int($count)) {
                            continue;
                        }
                        $object[$relation] = $count;
                    }
                }

                if (!isset($engine->entityProfiles[$entity])) {
                    $engine->entityProfiles[$entity] = [
                        'as_subject' => [],
                        'as_object' => [],
                    ];
                }

                foreach ($subject as $relation => $count) {
                    $engine->entityProfiles[$entity]['as_subject'][$relation] = $count;
                }

                foreach ($object as $relation => $count) {
                    $engine->entityProfiles[$entity]['as_object'][$relation] = $count;
                }
            }
        }

        if (isset($payload['verbs']) && is_array($payload['verbs'])) {
            foreach ($payload['verbs'] as $verb) {
                if (!is_string($verb)) {
                    continue;
                }
                $normalized = $engine->normalizeVerb($verb);
                if ($normalized === '') {
                    continue;
                }
                $engine->verbLexicon[$normalized] = true;
            }
        }

        if (isset($payload['document_signals']) && is_array($payload['document_signals'])) {
            $documentSignals = $payload['document_signals'];
            $count = $documentSignals['count'] ?? 0;
            if (is_numeric($count) && (int) $count > 0) {
                $engine->documentSignalCount = (int) $count;
            }

            if (isset($documentSignals['sums']) && is_array($documentSignals['sums'])) {
                foreach (['uniqueness', 'freshness', 'quality', 'consistency'] as $key) {
                    $value = $documentSignals['sums'][$key] ?? 0.0;
                    if (!is_numeric($value)) {
                        $value = 0.0;
                    }
                    $engine->documentSignalSums[$key] = (float) $value;
                }
            }
        }

        return $engine;
    }

    /**
     * Retrieve aggregated profiles for entities.
     *
     * @return array<string, array{as_subject: array<string, int>, as_object: array<string, int>}>
     */
    public function getEntityProfiles(): array
    {
        $result = [];
        foreach ($this->entityProfiles as $entity => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $subject = $profile['as_subject'] ?? [];
            $subjectCounts = [];
            if (is_array($subject)) {
                ksort($subject);
                foreach ($subject as $relation => $count) {
                    if (!is_string($relation)) {
                        continue;
                    }
                    $subjectCounts[$relation] = (int) $count;
                }
            }

            $object = $profile['as_object'] ?? [];
            $objectCounts = [];
            if (is_array($object)) {
                ksort($object);
                foreach ($object as $relation => $count) {
                    if (!is_string($relation)) {
                        continue;
                    }
                    $objectCounts[$relation] = (int) $count;
                }
            }

            $result[$entity] = [
                'as_subject' => $subjectCounts,
                'as_object' => $objectCounts,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Build a cross-reference index describing how entities connect across the graph.
     *
     * @return array<string, array{
     *     entity: string,
     *     facts: array<int, array{direction: string, relation: string, counterpart: string}>,
     *     synonyms: array<int, string>,
     *     context: array{as_subject: array<string, int>, as_object: array<string, int>},
     *     ranking: array{
     *         score: float,
     *         eligible: bool,
     *         signals: array{uniqueness: float, freshness: float, quality: float, authority: float, consistency: float},
     *         support: array{incoming_links: int, outgoing_links: int, fact_count: int}
     *     }
     * }>
     */
    public function buildCrossReferences(): array
    {
        $references = [];
        $documentSignals = $this->aggregateDocumentSignals();

        $ensure = static function (string $entity) use (&$references): void {
            if (!isset($references[$entity])) {
                $references[$entity] = [
                    'entity' => $entity,
                    'facts' => [],
                    'synonyms' => [],
                    'context' => [
                        'as_subject' => [],
                        'as_object' => [],
                    ],
                ];
            }
        };

        foreach ($this->graph as $relation => $subjects) {
            if (!is_string($relation) || $relation === '' || !is_array($subjects)) {
                continue;
            }

            foreach ($subjects as $subject => $objects) {
                if (!is_string($subject) || $subject === '' || !is_array($objects)) {
                    continue;
                }

                foreach ($objects as $object => $flag) {
                    if (!is_string($object) || $object === '' || $flag !== true) {
                        continue;
                    }

                    $ensure($subject);
                    $ensure($object);

                    $references[$subject]['facts'][] = [
                        'direction' => 'outgoing',
                        'relation' => $relation,
                        'counterpart' => $object,
                    ];

                    $references[$object]['facts'][] = [
                        'direction' => 'incoming',
                        'relation' => $relation,
                        'counterpart' => $subject,
                    ];
                }
            }
        }

        foreach ($this->synonyms as $entity => $synonyms) {
            if (!is_string($entity) || $entity === '' || !is_array($synonyms)) {
                continue;
            }

            $ensure($entity);

            $values = [];
            foreach ($synonyms as $synonym => $flag) {
                if (!is_string($synonym) || $synonym === '' || $flag !== true) {
                    continue;
                }
                $values[] = $synonym;
            }

            $merged = array_merge($references[$entity]['synonyms'], $values);
            $merged = array_values(array_unique($merged));
            sort($merged);
            $references[$entity]['synonyms'] = $merged;
        }

        $relatedIndex = $this->getRelatedTerms();
        foreach ($relatedIndex as $entity => $entries) {
            if (!isset($references[$entity])) {
                if (!$this->isTrackedEntity($entity)) {
                    continue;
                }
                $ensure($entity);
            } else {
                $ensure($entity);
            }

            $normalisedRelated = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $relatedEntity = (string) ($entry['entity'] ?? '');
                if ($relatedEntity === '') {
                    continue;
                }

                $normalisedRelated[] = [
                    'entity' => $relatedEntity,
                    'score' => (float) ($entry['score'] ?? 0.0),
                ];
            }

            if ($normalisedRelated === []) {
                continue;
            }

            $references[$entity]['related_terms'] = $normalisedRelated;
        }

        foreach ($this->entityProfiles as $entity => $profile) {
            if (!is_string($entity) || $entity === '' || !is_array($profile)) {
                continue;
            }

            $ensure($entity);

            $subjectCounts = [];
            if (isset($profile['as_subject']) && is_array($profile['as_subject'])) {
                foreach ($profile['as_subject'] as $relation => $count) {
                    if (!is_string($relation)) {
                        continue;
                    }
                    $subjectCounts[$relation] = (int) $count;
                }
                ksort($subjectCounts);
            }

            $objectCounts = [];
            if (isset($profile['as_object']) && is_array($profile['as_object'])) {
                foreach ($profile['as_object'] as $relation => $count) {
                    if (!is_string($relation)) {
                        continue;
                    }
                    $objectCounts[$relation] = (int) $count;
                }
                ksort($objectCounts);
            }

            $references[$entity]['context'] = [
                'as_subject' => $subjectCounts,
                'as_object' => $objectCounts,
            ];
        }

        $authorityScores = $this->calculateAuthorityScores($references);

        foreach ($references as $entity => &$payload) {
            $facts = $payload['facts'];
            usort(
                $facts,
                static function (array $left, array $right): int {
                    $leftKey = [
                        $left['relation'] ?? '',
                        $left['direction'] ?? '',
                        $left['counterpart'] ?? '',
                    ];
                    $rightKey = [
                        $right['relation'] ?? '',
                        $right['direction'] ?? '',
                        $right['counterpart'] ?? '',
                    ];

                    return $leftKey <=> $rightKey;
                }
            );
            $payload['facts'] = array_values($facts);

            if (!isset($payload['synonyms'])) {
                $payload['synonyms'] = [];
            }
            if (!is_array($payload['synonyms'])) {
                $payload['synonyms'] = [];
            }

            if (!isset($payload['related_terms']) || !is_array($payload['related_terms'])) {
                $payload['related_terms'] = [];
            }

            $totalFacts = count($payload['facts']);
            $incomingLinks = 0;
            $outgoingLinks = 0;
            $relations = [];

            foreach ($payload['facts'] as $fact) {
                $relation = (string) ($fact['relation'] ?? '');
                if ($relation !== '') {
                    $relations[$relation] = true;
                }

                $direction = (string) ($fact['direction'] ?? '');
                if ($direction === 'incoming') {
                    $incomingLinks++;
                } elseif ($direction === 'outgoing') {
                    $outgoingLinks++;
                }
            }

            $relationDiversity = $totalFacts > 0 ? $this->clampScore(count($relations) / $totalFacts) : 0.0;
            $synonymBoost = $payload['synonyms'] === [] ? 0.0 : $this->clampScore(count($payload['synonyms']) / 6);
            $context = $payload['context'] ?? ['as_subject' => [], 'as_object' => []];
            $activityScore = $this->computeEntityActivity($context);

            $uniquenessSignal = $this->clampScore(($documentSignals['uniqueness'] * 0.6) + ($relationDiversity * 0.4));
            $freshnessSignal = $this->clampScore(($documentSignals['freshness'] * 0.7) + ($this->entityFreshnessFromFacts($payload['facts']) * 0.3));
            $qualitySignal = $this->clampScore(($documentSignals['quality'] * 0.7) + ($activityScore * 0.3));
            $consistencySignal = $this->clampScore(($documentSignals['consistency'] * 0.6) + ($relationDiversity * 0.2) + ($synonymBoost * 0.2));
            $authoritySignal = $authorityScores[$entity] ?? 0.35;

            $score = $this->clampScore(($uniquenessSignal + $freshnessSignal + $qualitySignal + $authoritySignal + $consistencySignal) / 5);
            $payload['ranking'] = [
                'score' => round($score, 4),
                'eligible' => $score >= 0.45,
                'signals' => [
                    'uniqueness' => round($uniquenessSignal, 4),
                    'freshness' => round($freshnessSignal, 4),
                    'quality' => round($qualitySignal, 4),
                    'authority' => round($authoritySignal, 4),
                    'consistency' => round($consistencySignal, 4),
                ],
                'support' => [
                    'incoming_links' => $incomingLinks,
                    'outgoing_links' => $outgoingLinks,
                    'fact_count' => $totalFacts,
                ],
            ];
        }
        unset($payload);

        ksort($references);

        return $references;
    }

    /**
     * @return array{uniqueness: float, freshness: float, quality: float, consistency: float}
     */
    private function aggregateDocumentSignals(): array
    {
        if ($this->documentSignalCount <= 0) {
            return [
                'uniqueness' => 0.5,
                'freshness' => 0.5,
                'quality' => 0.5,
                'consistency' => 0.5,
            ];
        }

        $averages = [];
        foreach ($this->documentSignalSums as $key => $value) {
            $averages[$key] = $this->clampScore($value / $this->documentSignalCount);
        }

        return $averages;
    }

    /**
     * @param array<string, array{entity: string, facts: array<int, array{direction: string, relation: string, counterpart: string}>, synonyms: array<int, string>, context: array{as_subject: array<string, int>, as_object: array<string, int>}}> $references
     * @return array<string, float>
     */
    private function calculateAuthorityScores(array $references): array
    {
        $incoming = [];
        $maxIncoming = 0;

        foreach ($references as $entity => $payload) {
            $facts = $payload['facts'] ?? [];
            $count = 0;
            if (is_array($facts)) {
                foreach ($facts as $fact) {
                    if (!is_array($fact)) {
                        continue;
                    }
                    $direction = (string) ($fact['direction'] ?? '');
                    if ($direction === 'incoming') {
                        $count++;
                    }
                }
            }
            $incoming[$entity] = $count;
            if ($count > $maxIncoming) {
                $maxIncoming = $count;
            }
        }

        if ($maxIncoming === 0) {
            $baseline = 0.35;
            return array_fill_keys(array_keys($references), $baseline);
        }

        $scores = [];
        foreach ($incoming as $entity => $count) {
            $scores[$entity] = $this->clampScore(log(1 + $count) / log(1 + $maxIncoming));
        }

        return $scores;
    }

    /**
     * @param array{as_subject?: array<string, int>, as_object?: array<string, int>}|mixed $context
     */
    private function computeEntityActivity($context): float
    {
        if (!is_array($context)) {
            return 0.0;
        }

        $subject = $context['as_subject'] ?? [];
        $object = $context['as_object'] ?? [];

        $subjectTotal = 0;
        if (is_array($subject)) {
            foreach ($subject as $count) {
                if (!is_numeric($count)) {
                    continue;
                }
                $subjectTotal += (int) $count;
            }
        }

        $objectTotal = 0;
        if (is_array($object)) {
            foreach ($object as $count) {
                if (!is_numeric($count)) {
                    continue;
                }
                $objectTotal += (int) $count;
            }
        }

        $total = $subjectTotal + $objectTotal;

        if ($total <= 0) {
            return 0.0;
        }

        return $this->clampScore(min(1.0, $total / 12));
    }

    /**
     * @param array<int, array{direction?: string, relation?: string, counterpart?: string}> $facts
     */
    private function entityFreshnessFromFacts(array $facts): float
    {
        $latestYear = null;

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $counterpart = (string) ($fact['counterpart'] ?? '');
            if ($counterpart === '') {
                continue;
            }

            $matches = [];
            $result = preg_match_all('/\b(19|20)\d{2}\b/u', $counterpart, $matches);
            if ($result === false || $result === 0) {
                continue;
            }

            foreach ($matches[0] as $year) {
                $yearValue = (int) $year;
                if ($latestYear === null || $yearValue > $latestYear) {
                    $latestYear = $yearValue;
                }
            }
        }

        if ($latestYear === null) {
            return 0.5;
        }

        $currentYear = (int) date('Y');
        $delta = max(0, $currentYear - $latestYear);

        return $this->clampScore(1 - min(1, $delta / 8));
    }

    private function isTrackedEntity(string $entity): bool
    {
        if ($entity === '') {
            return false;
        }

        if (isset($this->entityProfiles[$entity])) {
            return true;
        }

        if (isset($this->synonyms[$entity])) {
            return true;
        }

        foreach ($this->synonyms as $values) {
            if (isset($values[$entity])) {
                return true;
            }
        }

        return false;
    }

    private function scoreRelatedTerm(string $left, string $right, float $weight): float
    {
        if ($weight <= 0.0) {
            return 0.0;
        }

        $leftFrequency = $this->relatedFrequency[$left] ?? 0.0;
        $rightFrequency = $this->relatedFrequency[$right] ?? 0.0;
        if ($leftFrequency <= 0.0 || $rightFrequency <= 0.0) {
            return 0.0;
        }

        $normalised = $weight / sqrt($leftFrequency * $rightFrequency);
        if ($normalised > 1.0) {
            $normalised = 1.0;
        }

        return $normalised;
    }

    private function clampScore(float $value): float
    {
        if ($value < 0) {
            return 0.0;
        }

        if ($value > 1) {
            return 1.0;
        }

        return $value;
    }

    /**
     * Return the learned verb lexicon.
     *
     * @return array<int, string>
     */
    public function getVerbLexicon(): array
    {
        $verbs = array_keys($this->verbLexicon);
        sort($verbs);
        return $verbs;
    }

    /**
     * Extract generic action triples from a sentence.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function extractActionTriples(string $sentence): array
    {
        $result = [];
        $trimmed = trim($sentence);
        if ($trimmed === '') {
            return $result;
        }

        $lower = strtolower($trimmed);
        foreach (self::$clauseOpeners as $opener => $_) {
            if ($this->startsWith($lower, $opener . ' ')) {
                return $result;
            }
        }

        $tokens = preg_split('/\s+/u', $trimmed);
        if ($tokens === false) {
            return $result;
        }

        $tokenCount = count($tokens);
        $normalizedTokens = [];
        for ($i = 0; $i < $tokenCount; $i++) {
            $normalizedTokens[$i] = $this->norm($tokens[$i]);
        }

        $verbIndex = null;
        $verbToken = null;

        for ($i = 0; $i < $tokenCount; $i++) {
            $normalizedToken = $normalizedTokens[$i];
            if ($normalizedToken === '') {
                continue;
            }

            if ($this->isStrongVerbCandidate($normalizedToken)) {
                $verbIndex = $i;
                $verbToken = $normalizedToken;
                break;
            }
        }

        if ($verbIndex === null) {
            for ($i = 0; $i < $tokenCount; $i++) {
                $normalizedToken = $normalizedTokens[$i];
                if ($normalizedToken === '') {
                    continue;
                }

                if ($this->isFallbackVerbCandidate($normalizedToken, $tokens[$i], $i, $tokens)) {
                    $verbIndex = $i;
                    $verbToken = $normalizedToken;
                    break;
                }
            }
        }

        if ($verbIndex === null || $verbToken === null) {
            return $result;
        }

        $subjectTokens = [];
        for ($i = 0; $i < $verbIndex; $i++) {
            if ($normalizedTokens[$i] === '') {
                continue;
            }
            $subjectTokens[] = $tokens[$i];
        }

        $objectTokens = [];
        for ($j = $verbIndex + 1; $j < $tokenCount; $j++) {
            if ($normalizedTokens[$j] === '') {
                continue;
            }
            $objectTokens[] = $tokens[$j];
        }

        $subjectRaw = trim(implode(' ', $subjectTokens));
        $objectRaw = trim(implode(' ', $objectTokens));
        if ($subjectRaw === '' || $objectRaw === '') {
            return $result;
        }

        if (
            !$this->isReasonableSpan($subjectTokens, $subjectRaw, 8, 80)
            || !$this->isReasonableSpan($objectTokens, $objectRaw, 12, 120)
        ) {
            return $result;
        }

        $verbBase = $this->normalizeVerb($verbToken);
        if ($verbBase === '') {
            return $result;
        }

        $relation = 'action-' . $verbBase;
        $this->addTriple($subjectRaw, $relation, $objectRaw);

        $subject = $this->normalizeEntity($subjectRaw);
        $object = $this->normalizeEntity($objectRaw);
        if ($subject === '' || $object === '') {
            return $result;
        }

        $result[] = [$subject, $this->norm($relation), $object];

        return $result;
    }

    private function ensureEntityProfile(string $entity): void
    {
        if (!isset($this->entityProfiles[$entity])) {
            $this->entityProfiles[$entity] = [
                'as_subject' => [],
                'as_object' => [],
            ];
        }
    }

    private function isStrongVerbCandidate(string $normalizedToken): bool
    {
        if ($normalizedToken === '') {
            return false;
        }

        $lower = strtolower($normalizedToken);
        if (isset(self::$nonActionVerbs[$lower])) {
            return false;
        }

        if (isset($this->verbLexicon[$lower])) {
            return true;
        }

        if (preg_match('/(?:ed|ing|ize|ise|ify|ized|ised)$/', $lower)) {
            return true;
        }

        if (preg_match('/ies$/', $lower)) {
            return true;
        }

        if (preg_match('/es$/', $lower) && !preg_match('/ss$/', $lower) && strlen($lower) > 3) {
            return true;
        }

        return false;
    }

    private function containsAlpha(string $text): bool
    {
        return preg_match('/[a-z]/i', $text) === 1;
    }

    private function isFallbackVerbCandidate(string $normalizedToken, string $rawToken, int $index, array $tokens): bool
    {
        if ($normalizedToken === '') {
            return false;
        }

        $lower = strtolower($normalizedToken);
        if (isset(self::$nonActionVerbs[$lower])) {
            return false;
        }

        if ($index === 0) {
            return false;
        }

        if (!$this->hasObjectTokens($tokens, $index + 1)) {
            return false;
        }

        $cleanRaw = preg_replace('/^[^A-Za-z]+|[^A-Za-z]+$/', '', $rawToken);
        if (!is_string($cleanRaw) || $cleanRaw === '') {
            return false;
        }

        if (ctype_upper($cleanRaw[0])) {
            return false;
        }

        foreach (self::$nounSuffixes as $suffix) {
            if ($this->endsWith($lower, $suffix)) {
                return false;
            }
        }

        for ($i = 0; $i < $index; $i++) {
            $prior = $this->norm($tokens[$i]);
            if ($prior === '') {
                continue;
            }
            if (isset(self::$determinants[$prior])) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function normalizeVerb(string $verb): string
    {
        $lower = strtolower(trim($verb));
        $lower = preg_replace('/[^a-z]/', '', $lower);
        if ($lower === null || $lower === '') {
            return '';
        }

        if (isset(self::$nonActionVerbs[$lower])) {
            return '';
        }

        $original = $lower;

        if (preg_match('/ies$/', $lower)) {
            $candidate = substr($lower, 0, -3) . 'y';
            if ($candidate !== '') {
                $lower = $candidate;
            }
        } elseif (preg_match('/ing$/', $lower)) {
            $candidate = substr($lower, 0, -3);
            if ($candidate !== '') {
                if (strlen($candidate) > 2 && substr($candidate, -1) === substr($candidate, -2, 1)) {
                    $candidate = substr($candidate, 0, -1);
                }
                $lower = $candidate;
            }
        } elseif (preg_match('/ed$/', $lower)) {
            $candidate = substr($lower, 0, -2);
            if ($candidate !== '') {
                if (strlen($candidate) > 2 && substr($candidate, -1) === substr($candidate, -2, 1)) {
                    $candidate = substr($candidate, 0, -1);
                }
                $lower = $candidate;
            }
        } elseif (preg_match('/es$/', $lower)) {
            $candidate = substr($lower, 0, -2);
            if ($candidate !== '') {
                $lower = $candidate;
            }
        } elseif (preg_match('/s$/', $lower) && !preg_match('/ss$/', $lower)) {
            $candidate = substr($lower, 0, -1);
            if ($candidate !== '') {
                $lower = $candidate;
            }
        }

        if ($lower === '') {
            $lower = $original;
        }

        $this->verbLexicon[$lower] = true;

        return $lower;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $needleLength = strlen($needle);
        if ($needleLength > strlen($haystack)) {
            return false;
        }

        return substr($haystack, -$needleLength) === $needle;
    }

    private function hasObjectTokens(array $tokens, int $startIndex): bool
    {
        $count = count($tokens);
        for ($i = $startIndex; $i < $count; $i++) {
            if ($this->norm($tokens[$i]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Basic sanity checks for extracted subject/object spans.
     *
     * @param array<int, string> $tokens
     */
    private function isReasonableSpan(array $tokens, string $rawText, int $maxTokens, int $maxLength): bool
    {
        if ($rawText === '') {
            return false;
        }

        if (strlen($rawText) > $maxLength) {
            return false;
        }

        if ($this->looksLikeUrl($rawText)) {
            return false;
        }

        if (!$this->containsAlpha($rawText)) {
            return false;
        }

        $meaningfulTokens = 0;
        $alphabeticTokens = 0;

        foreach ($tokens as $token) {
            $normalized = $this->norm($token);
            if ($normalized === '') {
                continue;
            }

            $meaningfulTokens++;
            if (preg_match('/[a-z]/i', $normalized) === 1) {
                $alphabeticTokens++;
            }

            if ($meaningfulTokens > $maxTokens) {
                return false;
            }
        }

        if ($meaningfulTokens === 0 || $alphabeticTokens === 0) {
            return false;
        }

        if (!$this->spanHasLexiconSignal($tokens)) {
            return false;
        }

        return true;
    }

    private function looksLikeUrl(string $text): bool
    {
        return preg_match('/https?:\/\/|www\./i', $text) === 1;
    }

    /**
     * Determine whether a span contains a meaningful English signal.
     *
     * @param array<int, string> $tokens
     */
    private function spanHasLexiconSignal(array $tokens): bool
    {
        $candidateCount = 0;
        $recognized = 0;
        $capitalized = 0;
        $allCapitalized = true;

        foreach ($tokens as $token) {
            $normalized = $this->norm($token);
            if ($normalized === '') {
                continue;
            }

            $candidateCount++;

            if ($this->englishLexicon->contains($normalized)) {
                $recognized++;
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z\-]+$/', $token) === 1 || preg_match('/^[A-Z]{2,}$/', $token) === 1) {
                $capitalized++;
            } else {
                $allCapitalized = false;
            }
        }

        if ($candidateCount === 0) {
            return false;
        }

        if ($recognized > 0) {
            return true;
        }

        if ($candidateCount === $capitalized && $capitalized > 0) {
            return true;
        }

        if ($capitalized >= 2 && $allCapitalized) {
            return true;
        }

        return false;
    }
}
