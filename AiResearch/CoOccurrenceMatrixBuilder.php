<?php
namespace AiResearch;

/**
 * Build a word-context co-occurrence matrix from tokenised documents.
 *
 * The builder uses a symmetric sliding window and applies a configurable
 * distance-based decay to context contributions. By default an inverse-distance
 * policy (1 / distance) is used, but callers can switch to a linear decay or
 * supply their own tuning via {@see setDecayPolicy()}.
 */
class CoOccurrenceMatrixBuilder
{
    /** @var int */
    private int $windowRadius;

    /**
     * The decay policy controls how quickly the weight decreases as the
     * context distance grows. Supported values: "inverse" (default) and
     * "linear".
     */
    private string $decayPolicy = 'inverse';

    /**
     * For the inverse policy this represents the exponent (1 / distance^k).
     * For the linear policy it represents the slope applied to (distance - 1).
     */
    private float $decayStrength = 1.0;

    /** @var array<int|string, array<int|string, float>> */
    private array $counts = [];

    /** @var array<int|string, float> */
    private array $sumRow = [];

    /** @var array<int|string, float> */
    private array $sumCol = [];

    private float $sumAll = 0.0;

    private bool $finalized = false;

    public function __construct(int $windowRadius = 2, string $decayPolicy = 'inverse', ?float $decayStrength = null)
    {
        $this->windowRadius = max(1, $windowRadius);
        $this->setDecayPolicy($decayPolicy, $decayStrength);
    }

    /**
     * Configure the decay policy used to weight context positions.
     *
     * @param string     $policy   Either "inverse" (1 / distance^k) or
     *                             "linear" (max(0, 1 - k * (distance - 1))).
     * @param float|null $strength Optional tuning parameter. Defaults to 1 for
     *                             inverse decay and 1 / windowRadius for linear
     *                             decay.
     */
    public function setDecayPolicy(string $policy, ?float $strength = null): void
    {
        $policy = strtolower($policy);
        if ($policy !== 'inverse' && $policy !== 'linear') {
            throw new \InvalidArgumentException('Unknown decay policy: ' . $policy);
        }

        if ($strength === null) {
            $strength = ($policy === 'linear') ? 1.0 / $this->windowRadius : 1.0;
        }

        if ($strength < 0.0) {
            throw new \InvalidArgumentException('Decay strength must be >= 0.');
        }

        $this->decayPolicy = $policy;
        $this->decayStrength = $strength;
        $this->finalized = false;
    }

    /**
     * @return array{policy: string, strength: float}
     */
    public function getDecayPolicy(): array
    {
        return ['policy' => $this->decayPolicy, 'strength' => $this->decayStrength];
    }

    /**
     * Add a tokenised document to the matrix.
     *
     * Each target/context pair within the sliding window contributes to
     * {@see $counts} with a weight determined by the configured decay policy.
     *
     * @param array<int, int|string> $tokens
     */
    public function addDocument(array $tokens): void
    {
        $count = count($tokens);
        if ($count === 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $target = $tokens[$i];
            if ($target === null || $target === '') {
                continue;
            }

            for ($offset = 1; $offset <= $this->windowRadius; $offset++) {
                $weight = $this->computeWeight($offset);
                if ($weight === 0.0) {
                    continue;
                }

                $left = $i - $offset;
                if ($left >= 0) {
                    $context = $tokens[$left];
                    if ($context !== null && $context !== '') {
                        $this->incrementCount($target, $context, $weight);
                    }
                }

                $right = $i + $offset;
                if ($right < $count) {
                    $context = $tokens[$right];
                    if ($context !== null && $context !== '') {
                        $this->incrementCount($target, $context, $weight);
                    }
                }
            }
        }

        $this->finalized = false;
    }

    private function computeWeight(int $distance): float
    {
        if ($distance <= 0) {
            return 0.0;
        }

        if ($this->decayPolicy === 'inverse') {
            if ($this->decayStrength === 0.0) {
                return 1.0;
            }
            return 1.0 / pow((float) $distance, $this->decayStrength);
        }

        $weight = 1.0 - $this->decayStrength * ($distance - 1);
        return ($weight > 0.0) ? $weight : 0.0;
    }

    /**
     * @param int|string $target
     * @param int|string $context
     */
    private function incrementCount($target, $context, float $weight): void
    {
        if (!isset($this->counts[$target])) {
            $this->counts[$target] = [];
        }
        if (!isset($this->counts[$target][$context])) {
            $this->counts[$target][$context] = 0.0;
        }
        $this->counts[$target][$context] += $weight;
    }

    /**
     * Finalise accumulated statistics.
     *
     * @return array{counts: array<int|string, array<int|string, float>>, sumAll: float, sumRow: array<int|string, float>, sumCol: array<int|string, float>}
     */
    public function finalize(): array
    {
        if (!$this->finalized) {
            $this->sumRow = [];
            $this->sumCol = [];
            $this->sumAll = 0.0;

            foreach ($this->counts as $target => $contexts) {
                $rowSum = 0.0;
                foreach ($contexts as $context => $value) {
                    $rowSum += $value;
                    if (!isset($this->sumCol[$context])) {
                        $this->sumCol[$context] = 0.0;
                    }
                    $this->sumCol[$context] += $value;
                }

                $this->sumRow[$target] = $rowSum;
                $this->sumAll += $rowSum;
            }

            $this->finalized = true;
        }

        return [
            'counts' => $this->counts,
            'sumAll' => $this->sumAll,
            'sumRow' => $this->sumRow,
            'sumCol' => $this->sumCol,
        ];
    }
}
