<?php

declare(strict_types=1);

namespace MDP\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a service is not found in the container.
 *
 * This exception is thrown when attempting to resolve a service that:
 * - Does not have a registered binding
 * - Is not a resolvable class
 * - Does not exist at all
 */
class NotFoundException extends \Exception implements NotFoundExceptionInterface
{
}
