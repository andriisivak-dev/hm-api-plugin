<?php
/**
 * @package CSP\Core
 */

declare(strict_types=1);

namespace CSP\Core;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = function ($container) use ($factory, $abstract) {
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = $factory($container);
            }
            return $this->instances[$abstract];
        };
    }

    public function get(string $abstract)
    {
        // already instantiated
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // binding exists
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]($this);
        }

        // auto-resolve (basic)
        if (class_exists($abstract)) {
            return new $abstract();
        }

        throw new \Exception("Cannot resolve {$abstract}");
    }
}