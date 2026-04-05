<?php

declare(strict_types=1);

namespace MDP\Container;

use MDP\Container\Exceptions\CircularDependencyException;
use MDP\Container\Exceptions\ContainerException;
use MDP\Container\Exceptions\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class Container implements ContainerInterface
{
    private const SINGLETON = 'singleton';
    private const TRANSIENT = 'transient';
    private const FACTORY = 'factory';

    /** @var array<string, array{concrete: callable|string, lifecycle: string}> */
    private array $entries = [];

    /** @var array<string, object> */
    private array $singletons = [];

    /** @var array<string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    /** @var string[] */
    private array $resolutionStack = [];

    /**
     * Register a singleton binding.
     *
     * @param string $id The service identifier
     * @param callable|string $concrete The concrete implementation
     * @return void
     */
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->set($id, $concrete, self::SINGLETON);
    }

    /**
     * Register a transient binding (always creates new instances).
     *
     * @param string $id The service identifier
     * @param callable|string $concrete The concrete implementation
     * @return void
     */
    public function transient(string $id, callable|string $concrete): void
    {
        $this->set($id, $concrete, self::TRANSIENT);
    }

    /**
     * Register a factory binding.
     *
     * @param string $id The service identifier
     * @param callable $factory The factory function
     * @return void
     */
    public function factory(string $id, callable $factory): void
    {
        $this->set($id, $factory, self::FACTORY);
    }

    /**
     * Register a binding.
     *
     * @param string $id The service identifier
     * @param callable|string $concrete The concrete implementation
     * @param string $lifecycle The lifecycle type (singleton, transient, factory)
     * @return void
     */
    public function set(string $id, callable|string $concrete, string $lifecycle = self::TRANSIENT): void
    {
        unset($this->singletons[$id]);
        $this->entries[$id] = [
            'concrete' => $concrete,
            'lifecycle' => $lifecycle,
        ];
    }

    /**
     * Get a service from the container.
     *
     * @param string $id
     * @return mixed
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function get(string $id): mixed
    {
        if ($this->has($id)) {
            /** @var array{concrete: callable|string, lifecycle: string} $entry */
            $entry = $this->entries[$id];
            $lifecycle = $entry['lifecycle'];
            $concrete = $entry['concrete'];

            // Return cached singleton
            if ($lifecycle === self::SINGLETON && isset($this->singletons[$id])) {
                return $this->singletons[$id];
            }

            if (!is_string($concrete)) {
                // $concrete is a callable (Closure, array, etc.)
                $instance = $concrete($this);
            } else {
                // $concrete is a string (class name)
                $instance = $this->resolve($concrete);
            }

            // Cache singleton
            if ($lifecycle === self::SINGLETON) {
                $this->singletons[$id] = $instance;
            }

            return $instance;
        }

        return $this->resolve($id);
    }

    /**
     * Check if a service is registered in the container.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    /**
     * Resolve a class by its fully qualified name, automatically wiring dependencies.
     *
     * @param string $id Fully qualified class name
     * @return object
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function resolve(string $id): object
    {
        // Detect circular dependencies
        if (in_array($id, $this->resolutionStack, true)) {
            $chain = implode(' -> ', array_merge($this->resolutionStack, [$id]));

            throw new CircularDependencyException(
                "Circular dependency detected: {$chain}"
            );
        }

        $this->resolutionStack[] = $id;

        try {
            $reflectionClass = $this->getReflectionClass($id);

            if (!$reflectionClass->isInstantiable()) {
                throw new ContainerException(
                    "Class \"{$id}\" is not instantiable (likely abstract or an interface)."
                );
            }

            $constructor = $reflectionClass->getConstructor();

            if ($constructor === null) {
                $instance = new $id();
            } else {
                $parameters = $constructor->getParameters();
                $dependencies = array_map(
                    function (ReflectionParameter $param) use ($id): mixed {
                        return $this->resolveDependency($param, $id);
                    },
                    $parameters
                );

                $instance = $reflectionClass->newInstanceArgs($dependencies);
            }

            array_pop($this->resolutionStack);

            return $instance;
        } catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
            // Re-throw container exceptions
            array_pop($this->resolutionStack);

            throw $e;
        } catch (ReflectionException $e) {
            array_pop($this->resolutionStack);

            throw new NotFoundException(
                "Failed to resolve \"{$id}\": {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        } catch (\Throwable $e) {
            array_pop($this->resolutionStack);

            throw new ContainerException(
                "Error resolving \"{$id}\": {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Resolve a single dependency parameter.
     *
     * @param ReflectionParameter $param
     * @param string $parentId
     * @return mixed
     * @throws ContainerException
     */
    private function resolveDependency(ReflectionParameter $param, string $parentId): mixed
    {
        $name = $param->getName();
        $type = $param->getType();

        if ($type === null) {
            throw new ContainerException(
                "Cannot resolve \"{$parentId}\" - constructor parameter \"{$name}\" is missing a type hint. "
                . 'Add a type declaration or use a factory function for explicit wiring.'
            );
        }

        if ($type instanceof ReflectionUnionType) {
            throw new ContainerException(
                "Cannot resolve \"{$parentId}\" - constructor parameter \"{$name}\" has a union type. "
                . 'Union types are ambiguous for automatic resolution. Use a factory function instead.'
            );
        }

        if (!($type instanceof ReflectionNamedType)) {
            throw new ContainerException(
                "Cannot resolve \"{$parentId}\" - unsupported type for parameter \"{$name}\"."
            );
        }

        if ($type->isBuiltin()) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw new ContainerException(
                "Cannot resolve \"{$parentId}\" - constructor parameter \"{$name}\" is a builtin type "
                . 'with no default value. Use a factory function for custom wiring.'
            );
        }

        return $this->get($type->getName());
    }

    /**
     * Get a cached reflection class or create and cache it.
     *
     * @param string $id
     * @return ReflectionClass<object>
     * @throws ReflectionException
     */
    private function getReflectionClass(string $id): ReflectionClass
    {
        if (isset($this->reflectionCache[$id])) {
            return $this->reflectionCache[$id];
        }

        /** @var class-string<object> $id */
        $reflectionClass = new ReflectionClass($id);
        $this->reflectionCache[$id] = $reflectionClass;

        return $reflectionClass;
    }

    /**
     * Clear all singletons (useful for testing).
     *
     * @return void
     */
    public function clearSingletons(): void
    {
        $this->singletons = [];
    }

    /**
     * Clear reflection cache (useful for debugging).
     *
     * @return void
     */
    public function clearReflectionCache(): void
    {
        $this->reflectionCache = [];
    }
}
