<?php

declare(strict_types=1);

namespace MDP\Container\Tests;

use MDP\Container\Container;
use MDP\Container\Exceptions\CircularDependencyException;
use MDP\Container\Exceptions\ContainerException;
use MDP\Container\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

// ============================================================================
// Test Fixtures
// ============================================================================

interface ServiceInterface
{
    public function getValue(): string;
}

class SimpleService
{
}

class ConcreteService implements ServiceInterface
{
    public function getValue(): string
    {
        return 'concrete';
    }
}

class ServiceWithDependency
{
    private SimpleService $dependency;

    public function __construct(SimpleService $dependency)
    {
        $this->dependency = $dependency;
    }

    public function getDependency(): SimpleService
    {
        return $this->dependency;
    }
}

class ServiceWithNestedDependency
{
    private ServiceWithDependency $dependency;

    public function __construct(ServiceWithDependency $dependency)
    {
        $this->dependency = $dependency;
    }

    public function getDependency(): ServiceWithDependency
    {
        return $this->dependency;
    }
}

class ServiceWithMultipleDependencies
{
    private SimpleService $serviceA;

    private SimpleService $serviceB;

    public function __construct(SimpleService $serviceA, SimpleService $serviceB)
    {
        $this->serviceA = $serviceA;
        $this->serviceB = $serviceB;
    }

    public function getServiceA(): SimpleService
    {
        return $this->serviceA;
    }

    public function getServiceB(): SimpleService
    {
        return $this->serviceB;
    }
}

abstract class AbstractService
{
}

class ServiceWithNoTypeHint
{
    public function __construct($dependency)
    {
    }
}

class ServiceWithUnionType
{
    /**
     * @phpstan-param SimpleService|ServiceWithDependency $param
     */
    public function __construct(SimpleService|ServiceWithDependency $param)
    {
    }
}

class ServiceWithBuiltinType
{
    public function __construct(string $value)
    {
    }
}

class ServiceWithBuiltinTypeDefault
{
    private string $value;

    public function __construct(string $value = 'default')
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class CircularServiceA
{
    public function __construct(CircularServiceB $serviceB)
    {
    }
}

class CircularServiceB
{
    public function __construct(CircularServiceA $serviceA)
    {
    }
}

class IndirectCircularA
{
    public function __construct(IndirectCircularB $serviceB)
    {
    }
}

class IndirectCircularB
{
    public function __construct(IndirectCircularC $serviceC)
    {
    }
}

class IndirectCircularC
{
    public function __construct(IndirectCircularA $serviceA)
    {
    }
}

// ============================================================================
// Tests
// ============================================================================

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testGetNonExistentServiceResolvesByClassName(): void
    {
        $service = $this->container->get(SimpleService::class);

        $this->assertInstanceOf(SimpleService::class, $service);
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $this->container->set('service', SimpleService::class);

        $this->assertTrue($this->container->has('service'));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $this->assertFalse($this->container->has('nonexistent'));
    }

    public function testSetAndGetWithString(): void
    {
        $this->container->set('service', SimpleService::class);
        $service = $this->container->get('service');

        $this->assertInstanceOf(SimpleService::class, $service);
    }

    public function testSetAndGetWithCallable(): void
    {
        $this->container->set('service', static fn (Container $container) => new SimpleService());
        $service = $this->container->get('service');

        $this->assertInstanceOf(SimpleService::class, $service);
    }

    public function testGetWithDependencies(): void
    {
        $service = $this->container->get(ServiceWithDependency::class);

        $this->assertInstanceOf(ServiceWithDependency::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->getDependency());
    }

    public function testGetWithNestedDependencies(): void
    {
        $service = $this->container->get(ServiceWithNestedDependency::class);

        $this->assertInstanceOf(ServiceWithNestedDependency::class, $service);
        $this->assertInstanceOf(ServiceWithDependency::class, $service->getDependency());
        $this->assertInstanceOf(SimpleService::class, $service->getDependency()->getDependency());
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton('service', SimpleService::class);

        $service1 = $this->container->get('service');
        $service2 = $this->container->get('service');

        $this->assertSame($service1, $service2);
    }

    public function testTransientReturnsNewInstance(): void
    {
        $this->container->transient('service', SimpleService::class);

        $service1 = $this->container->get('service');
        $service2 = $this->container->get('service');

        $this->assertNotSame($service1, $service2);
    }

    public function testFactoryReturnsNewInstanceEachTime(): void
    {
        $this->container->factory('service', static fn (Container $container) => new SimpleService());

        $service1 = $this->container->get('service');
        $service2 = $this->container->get('service');

        $this->assertInstanceOf(SimpleService::class, $service1);
        $this->assertInstanceOf(SimpleService::class, $service2);
        $this->assertNotSame($service1, $service2);
    }

    public function testClearSingletons(): void
    {
        $this->container->singleton('service', SimpleService::class);
        $service1 = $this->container->get('service');

        $this->container->clearSingletons();
        $service2 = $this->container->get('service');

        $this->assertNotSame($service1, $service2);
    }

    public function testReflectionCaching(): void
    {
        $this->container->resolve(ServiceWithDependency::class);
        $this->container->resolve(ServiceWithDependency::class);

        // Should not throw; just verify no exceptions occur
        $this->assertTrue(true);
    }

    public function testThrowsNotFoundExceptionForNonExistentClass(): void
    {
        $this->expectException(NotFoundException::class);

        $this->container->resolve('NonExistentClass');
    }

    public function testThrowsContainerExceptionForAbstractClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->container->resolve(AbstractService::class);
    }

    public function testThrowsContainerExceptionForInterface(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->container->resolve(ServiceInterface::class);
    }

    public function testThrowsContainerExceptionForMissingTypeHint(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('missing a type hint');

        $this->container->resolve(ServiceWithNoTypeHint::class);
    }

    public function testThrowsContainerExceptionForUnionType(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('union type');

        $this->container->resolve(ServiceWithUnionType::class);
    }

    public function testThrowsContainerExceptionForBuiltinTypeWithNoDefault(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('builtin type');

        $this->container->resolve(ServiceWithBuiltinType::class);
    }

    public function testHandlesBuiltinTypeWithDefault(): void
    {
        $service = $this->container->resolve(ServiceWithBuiltinTypeDefault::class);

        $this->assertInstanceOf(ServiceWithBuiltinTypeDefault::class, $service);
        $this->assertEquals('default', $service->getValue());
    }

    public function testDetectsCircularDependency(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->resolve(CircularServiceA::class);
    }

    public function testDetectsIndirectCircularDependency(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->resolve(IndirectCircularA::class);
    }

    public function testSingletonDoesNotRecreateAfterSet(): void
    {
        $this->container->singleton('service', SimpleService::class);
        $service1 = $this->container->get('service');

        $this->container->set('service', SimpleService::class);
        $service2 = $this->container->get('service');

        $this->assertNotSame($service1, $service2);
    }

    public function testCallableReceivesContainerInstance(): void
    {
        $capturedContainer = null;
        $this->container->set('service', function (Container $container) use (&$capturedContainer): SimpleService {
            $capturedContainer = $container;

            return new SimpleService();
        });

        $this->container->get('service');

        $this->assertSame($this->container, $capturedContainer);
    }

    public function testResolveWithInterfaceBinding(): void
    {
        $this->container->set(ServiceInterface::class, ConcreteService::class);
        $service = $this->container->get(ServiceInterface::class);

        $this->assertInstanceOf(ConcreteService::class, $service);
    }

    public function testMultipleLevelsOfDependencies(): void
    {
        $service = $this->container->get(ServiceWithMultipleDependencies::class);

        $this->assertInstanceOf(ServiceWithMultipleDependencies::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->getServiceA());
        $this->assertInstanceOf(SimpleService::class, $service->getServiceB());
    }
}
