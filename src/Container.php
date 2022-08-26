<?php

declare(strict_types=1);

namespace MDP\Container;

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
    private array $entries = [];

    /**
     * @param string $id
     * @return mixed|object|string|null
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function get(string $id): mixed
    {
        if ($this->has($id)) {
            $entry = $this->entries[$id];

            if (is_callable($entry)) {
                return $entry($this);
            }

            $id = $entry;
        }

        return $this->resolve($id);
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    public function set(string $id, callable|string $concrete): void
    {
        $this->entries[$id] = $concrete;
    }

    /**
     * @param string $id
     * @return mixed|object|string|null
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function resolve(string $id): mixed
    {
        try {
            $reflectionClass = new ReflectionClass($id);
        } catch (ReflectionException $e) {
            throw new NotFoundException($e->getMessage(), $e->getCode(), $e);
        }
        if (!$reflectionClass->isInstantiable()) {
            throw new ContainerException('Class "' . $id . '" is not instantiable');
        }
        $constructor = $reflectionClass->getConstructor();
        if (!$constructor) {
            return new $id;
        }
        $parameters = $constructor->getParameters();
        if (!$parameters) {
            return new $id;
        }
        $dependencies = array_map(
            function (ReflectionParameter $param) use ($id) {
                $name = $param->getName();
                $type = $param->getType();
                if (!$type) {
                    throw new ContainerException(
                        'Failed to resolve class "' . $id . '" because param "' . $name . '" is missing a type hint'
                    );
                }
                if ($type instanceof ReflectionUnionType) {
                    throw new ContainerException(
                        'Failed to resolve class "' . $id . '" because of union type for param "' . $name . '"'
                    );
                }
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    return $this->get($type->getName());
                }
                throw new ContainerException(
                    'Failed to resolve class "' . $id . '" because invalid param "' . $name . '"'
                );
            },
            $parameters
        );
        return $reflectionClass->newInstanceArgs($dependencies);
    }
}