<?php

declare(strict_types=1);

namespace App\Intelligence;

use DateTimeImmutable;
use RuntimeException;

use function array_fill_keys;
use function array_keys;
use function dirname;
use function exp;
use function hash;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_dir;
use function is_numeric;
use function json_decode;
use function json_encode;
use function max;
use function mkdir;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class LogisticModel
{
    /**
     * @var array<string, float>
     */
    private array $weights;

    private float $bias;

    private ?string $version = null;

    /**
     * @param array<string, float> $weights
     */
    public function __construct(array $weights, float $bias)
    {
        $normalised = [];
        foreach ($weights as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $normalised[$key] = (float) $value;
        }

        $this->weights = $normalised;
        $this->bias = $bias;
    }

    public static function default(): self
    {
        return new self(
            [
                'avg_quality' => 1.35,
                'freshness' => 1.2,
                'graph_density' => 1.5,
                'entity_focus' => 0.85,
                'source_diversity' => 0.65,
                'semantic_alignment' => 1.45,
                'volume' => 0.72,
                'graph_support' => 1.05,
            ],
            -1.15
        );
    }

    public static function loadOrDefault(string $path): self
    {
        if (!is_file($path)) {
            return self::default();
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return self::default();
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return self::default();
        }

        $weights = isset($decoded['weights']) && is_array($decoded['weights']) ? $decoded['weights'] : [];
        $bias = isset($decoded['bias']) && is_numeric($decoded['bias']) ? (float) $decoded['bias'] : null;

        if ($bias === null || $weights === []) {
            return self::default();
        }

        $model = new self($weights, $bias);
        if (isset($decoded['version']) && is_string($decoded['version']) && $decoded['version'] !== '') {
            $model->version = $decoded['version'];
        }

        return $model;
    }

    /**
     * @param array<string, float> $features
     */
    public function predict(array $features): float
    {
        $z = $this->bias;
        foreach ($features as $name => $value) {
            if (!isset($this->weights[$name])) {
                continue;
            }

            $z += $this->weights[$name] * (float) $value;
        }

        return 1.0 / (1.0 + exp(-$z));
    }

    /**
     * @param array<string, float> $features
     *
     * @return array<string, float>
     */
    public function contributions(array $features): array
    {
        $contributions = [];
        foreach ($this->weights as $name => $weight) {
            $contributions[$name] = $weight * ($features[$name] ?? 0.0);
        }

        $contributions['_bias'] = $this->bias;

        return $contributions;
    }

    /**
     * @param array<int, array{features: array<string, float>, label: float|int}> $samples
     */
    public function train(array $samples, int $iterations = 120, float $learningRate = 0.15): void
    {
        if ($samples === []) {
            return;
        }

        $weights = $this->weights;
        $bias = $this->bias;
        $featureNames = array_keys($weights);
        $iterations = max(1, $iterations);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $gradients = array_fill_keys($featureNames, 0.0);
            $biasGradient = 0.0;

            foreach ($samples as $sample) {
                if (!isset($sample['features'], $sample['label']) || !is_array($sample['features'])) {
                    continue;
                }

                $features = $sample['features'];
                $label = (float) $sample['label'];

                $z = $bias;
                foreach ($featureNames as $name) {
                    $value = (float) ($features[$name] ?? 0.0);
                    $z += $weights[$name] * $value;
                }

                $prediction = 1.0 / (1.0 + exp(-$z));
                $error = $prediction - $label;

                foreach ($featureNames as $name) {
                    $value = (float) ($features[$name] ?? 0.0);
                    $gradients[$name] += $error * $value;
                }

                $biasGradient += $error;
            }

            $count = max(1, count($samples));
            foreach ($featureNames as $name) {
                $weights[$name] -= $learningRate * ($gradients[$name] / $count);
            }

            $bias -= $learningRate * ($biasGradient / $count);
        }

        $this->weights = $weights;
        $this->bias = $bias;
        $this->version = null;
    }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = [
            'bias' => $this->bias,
            'weights' => $this->weights,
            'version' => $this->version(),
            'trained_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode logistic model.');
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException('Failed to persist logistic model.');
        }
    }

    /**
     * @return array<string, float>
     */
    public function weights(): array
    {
        return $this->weights;
    }

    public function bias(): float
    {
        return $this->bias;
    }

    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $encoded = json_encode([
            'bias' => $this->bias,
            'weights' => $this->weights,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->version = hash('sha256', (string) $encoded);

        return $this->version;
    }
}
