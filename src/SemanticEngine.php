<?php

/**
 * Core semantic engine for knowledge graph extraction.
 */
class SemanticEngine
{
    /** @var array<string, array<string, array<string, true>>> */
    private array $graph = [];

    /** @var array<string, array<string, true>> */
    private array $synonyms = [];

    /**
     * Entity-centric aggregation of relations for lightweight profiling.
     *
     * @var array<string, array{as_subject: array<string, int>, as_object: array<string, int>}>
     */
    private array $entityProfiles = [];

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

            if (preg_match('/^(?P<left>.+?)\s+(aka|also known as|synonym of)\s+(?P<right>.+)$/iu', $sentence, $matches)) {
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

            if (preg_match('/^(?P<label>.+?)(?:\s*[:\-\x{2013}\x{2014}]\s*)(?P<desc>.+)$/u', $sentence, $matches)) {
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
     * Export the engine state.
     *
     * @return array{graph: array<string, array<string, array<string, true>>>, synonyms: array<string, array<string, true>>}
     */
    public function toArray(): array
    {
        return [
            'graph' => $this->graph,
            'synonyms' => $this->synonyms,
            'profiles' => $this->entityProfiles,
            'verbs' => array_keys($this->verbLexicon),
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
}
