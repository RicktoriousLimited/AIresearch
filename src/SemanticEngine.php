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

    /** @var array<string, true> */
    private static array $entityStopwords = [
        'a' => true,
        'an' => true,
        'the' => true,
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

            foreach ($relationPatterns as $pattern) {
                if (!preg_match($pattern['regex'], $sentence, $matches)) {
                    continue;
                }

                $subjectRaw = $matches['subject'];
                $objectRaw = $matches['object'];
                $this->addTriple($subjectRaw, $pattern['relation'], $objectRaw);
                $subject = $this->normalizeEntity($subjectRaw);
                $object = $this->normalizeEntity($objectRaw);
                if ($subject !== '' && $object !== '') {
                    $triples[] = [$subject, $this->norm($pattern['relation']), $object];
                }
                continue 2;
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
}
