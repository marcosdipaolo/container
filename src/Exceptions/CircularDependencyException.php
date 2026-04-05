<?php

declare(strict_types=1);

namespace MDP\Container\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/**
 * Exception thrown when a circular dependency is detected.
 *
 * This exception is thrown when the container detects a circular dependency
 * chain during service resolution (e.g., A depends on B, B depends on A).
 */
class CircularDependencyException extends \Exception implements ContainerExceptionInterface
{
}
