<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App;

use Closure;
use RuntimeException;

final class ServiceContainer
{
    /**
     * @var array<string, array{factory: Closure, shared: bool}>
     */
    private array $definitions = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @var array<string, bool>
     */
    private array $resolving = [];

    public function set(string $id, mixed $value, bool $shared = true): void
    {
        if ($value instanceof Closure) {
            $this->definitions[$id] = ['factory' => $value, 'shared' => $shared];
            if (!$shared) {
                unset($this->instances[$id]);
            }

            return;
        }

        $this->definitions[$id] = [
            'factory' => static fn(): mixed => $value,
            'shared' => true,
        ];
        $this->instances[$id] = $value;
    }

    public function factory(string $id, Closure $factory): void
    {
        $this->set($id, $factory, false);
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || array_key_exists($id, $this->instances);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new RuntimeException(sprintf('Service "%s" has not been defined.', $id));
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException(sprintf('Circular dependency detected while resolving "%s".', $id));
        }

        $this->resolving[$id] = true;

        $factory = $this->definitions[$id]['factory'];
        $service = $factory($this);

        unset($this->resolving[$id]);

        if ($this->definitions[$id]['shared']) {
            $this->instances[$id] = $service;
        }

        return $service;
    }
}
