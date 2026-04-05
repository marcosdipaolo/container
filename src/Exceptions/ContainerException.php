<?php

declare(strict_types=1);

namespace MDP\Container\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/**
 * Exception thrown when a container error occurs.
 *
 * This exception is thrown when the container encounters an error during
 * service resolution or binding, such as uninstantiable classes, missing
 * type hints, or invalid configurations.
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
{
}
