<?php
namespace AiResearch;

/**
 * Infer synonym relationships from PPMI statistics.
 *
 * @param array<int, array<int, float>> $ppmi        Sparse PPMI matrix keyed by word id.
 * @param array<int, string>            $idToWord    Mapping from word id to token.
 * @param float                         $minimumScore Cosine threshold for emitting synonyms.
 *
 * @return array<int, array{0: string, 1: string, 2: float}>
 */
function infer(array $ppmi, array $idToWord, float $minimumScore = 0.5): array
{
    $norms = computeNorms($ppmi);
    $contextToWords = buildContextIndex($ppmi);
    $candidatePairs = enumerateCandidatePairs($contextToWords);

    $results = [];
    foreach ($candidatePairs as [$leftId, $rightId]) {
        $leftVector = $ppmi[$leftId] ?? [];
        $rightVector = $ppmi[$rightId] ?? [];
        $leftNorm = $norms[$leftId] ?? 0.0;
        $rightNorm = $norms[$rightId] ?? 0.0;

        if ($leftNorm === 0.0 || $rightNorm === 0.0) {
            continue;
        }

        $score = cosineSimilarity($leftVector, $rightVector, $leftNorm, $rightNorm);
        if ($score + 1e-12 < $minimumScore) {
            continue;
        }

        if (!isset($idToWord[$leftId], $idToWord[$rightId])) {
            continue;
        }

        $leftWord = $idToWord[$leftId];
        $rightWord = $idToWord[$rightId];
        if ($leftWord <= $rightWord) {
            $pair = [$leftWord, $rightWord, $score];
        } else {
            $pair = [$rightWord, $leftWord, $score];
        }

        $results[] = $pair;
    }

    usort(
        $results,
        /**
         * @param array{0: string, 1: string, 2: float} $a
         * @param array{0: string, 1: string, 2: float} $b
         */
        static function (array $a, array $b): int {
            if ($a[2] === $b[2]) {
                if ($a[0] === $b[0]) {
                    return $a[1] <=> $b[1];
                }
                return $a[0] <=> $b[0];
            }

            return ($a[2] < $b[2]) ? 1 : -1;
        }
    );

    return $results;
}

/**
 * @param array<int, array<int, float>> $ppmi
 * @return array<int, float>
 */
function computeNorms(array $ppmi): array
{
    $norms = [];
    foreach ($ppmi as $wordId => $vector) {
        $sum = 0.0;
        foreach ($vector as $value) {
            if ($value != 0.0) {
                $sum += $value * $value;
            }
        }
        $norms[$wordId] = $sum > 0.0 ? sqrt($sum) : 0.0;
    }

    return $norms;
}

/**
 * @param array<int, array<int, float>> $ppmi
 * @return array<int, array<int, true>>
 */
function buildContextIndex(array $ppmi): array
{
    $contextToWords = [];
    foreach ($ppmi as $wordId => $vector) {
        foreach ($vector as $contextId => $value) {
            if ($value == 0.0) {
                continue;
            }

            if (!isset($contextToWords[$contextId])) {
                $contextToWords[$contextId] = [];
            }

            $contextToWords[$contextId][$wordId] = true;
        }
    }

    return $contextToWords;
}

/**
 * @param array<int, array<int, true>> $contextToWords
 * @return array<int, array{0: int, 1: int}>
 */
function enumerateCandidatePairs(array $contextToWords): array
{
    $pairs = [];
    foreach ($contextToWords as $words) {
        $wordIds = array_keys($words);
        $count = count($wordIds);
        if ($count < 2) {
            continue;
        }

        sort($wordIds, SORT_NUMERIC);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $wordIds[$i];
                $right = $wordIds[$j];
                $key = $left . ':' . $right;
                if (!isset($pairs[$key])) {
                    $pairs[$key] = [$left, $right];
                }
            }
        }
    }

    return array_values($pairs);
}

/**
 * @param array<int, float> $left
 * @param array<int, float> $right
 */
function cosineSimilarity(array $left, array $right, float $leftNorm, float $rightNorm): float
{
    if ($leftNorm === 0.0 || $rightNorm === 0.0) {
        return 0.0;
    }

    if (count($left) > count($right)) {
        [$left, $right] = [$right, $left];
    }

    $dot = 0.0;
    foreach ($left as $contextId => $value) {
        if (isset($right[$contextId])) {
            $dot += $value * $right[$contextId];
        }
    }

    if ($dot === 0.0) {
        return 0.0;
    }

    return $dot / ($leftNorm * $rightNorm);
}

