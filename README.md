# PSR-11 Dependency Injection Container

A production-ready, lightweight PSR-11 compliant dependency injection container for PHP with automatic constructor wiring, lifecycle management, and circular dependency detection.

## Features

✅ **PSR-11 Compliant** - Implements the standard `ContainerInterface`  
✅ **Automatic Wiring** - Resolves constructor dependencies without configuration  
✅ **Lifecycle Management** - Singleton, transient, and factory bindings  
✅ **Circular Dependency Detection** - Catches infinite dependency loops at resolution time  
✅ **Reflection Caching** - Optimized performance for repeated resolutions  
✅ **Type Safety** - Comprehensive error messages with actionable guidance  
✅ **Fully Tested** - Extensive PHPUnit test suite with high coverage  
✅ **Static Analysis** - PHPStan level 10 compliant  

## Installation

```bash
composer require marcosdipaolo/container
```

## Quick Start

```php
use MDP\Container\Container;

$container = new Container();

// Automatic wiring
$myService = $container->get(MyService::class);

// Register bindings
$container->singleton(DatabaseInterface::class, MySQLDatabase::class);
$container->transient(Logger::class, FileLogger::class);
$container->factory('timestamp', fn() => time());

// Get instances
$db = $container->get(DatabaseInterface::class);
$logger = $container->get(Logger::class);
$time = $container->get('timestamp');
```

## Usage Guide

### Automatic Wiring

The container can automatically resolve classes by analyzing their constructor dependencies:

```php
class UserRepository
{
    public function __construct(private DatabaseConnection $db) {}
}

class UserService
{
    public function __construct(private UserRepository $repo) {}
}

// No registration needed!
$userService = $container->get(UserService::class);
// All dependencies are automatically injected
```

### Lifecycle Management

#### Singleton Binding
Returns the same instance every time:

```php
$container->singleton(Logger::class, FileLogger::class);

$logger1 = $container->get(Logger::class);
$logger2 = $container->get(Logger::class);

assert($logger1 === $logger2); // True
```

#### Transient Binding
Creates a new instance every time:

```php
$container->transient(Request::class, HttpRequest::class);

$req1 = $container->get(Request::class);
$req2 = $container->get(Request::class);

assert($req1 !== $req2); // True - different instances
```

#### Factory Binding
Custom resolution logic with full control:

```php
$container->factory('config', function(Container $container) {
    return new Config(getenv('CONFIG_PATH'));
});

// Created fresh every time via the factory function
$config = $container->get('config');
```

### Interface Binding

Bind interfaces to concrete implementations:

```php
interface CacheStoreInterface {}
class RedisCache implements CacheStoreInterface {}

$container->singleton(CacheStoreInterface::class, RedisCache::class);

$cache = $container->get(CacheStoreInterface::class);
assert($cache instanceof RedisCache); // True
```

### Custom Resolution

Use factory functions for complex setup:

```php
$container->set('pdo', function(Container $container) {
    return new PDO('sqlite::memory:');
});

// With more control - access other services
$container->set(UserRepository::class, function(Container $container) {
    $db = $container->get('pdo');
    $logger = $container->get(Logger::class);
    return new UserRepository($db, $logger);
});
```

## API Reference

### Constructor

```php
$container = new Container();
```

### Methods

#### `set(string $id, callable|string $concrete, string $lifecycle = 'transient'): void`

Register a binding with optional lifecycle:

```php
$container->set('name', ConcreteClass::class);
$container->set('factory', fn($c) => new Thing(), 'transient');
```

#### `singleton(string $id, callable|string $concrete): void`

Register a singleton binding (single instance):

```php
$container->singleton(Database::class, MySQLDatabase::class);
```

#### `transient(string $id, callable|string $concrete): void`

Register a transient binding (new instance each time):

```php
$container->transient(Request::class, HttpRequest::class);
```

#### `factory(string $id, callable $factory): void`

Register a factory binding:

```php
$container->factory('timestamp', fn($c) => time());
```

#### `get(string $id): mixed`

Resolve a service from the container:

```php
$service = $container->get(MyService::class);
```

Throws `NotFoundException` if the service cannot be resolved.

#### `has(string $id): bool`

Check if a service is registered:

```php
if ($container->has('config')) {
    $config = $container->get('config');
}
```

#### `resolve(string $id): object`

Resolve a class by name without prior registration:

```php
$service = $container->resolve(MyService::class);
// All constructor dependencies are wired automatically
```

Throws `NotFoundException` if the class doesn't exist.  
Throws `ContainerException` if the class cannot be instantiated.

#### `clearSingletons(): void`

Clear all cached singleton instances:

```php
$container->clearSingletons();
// Next get() will create fresh instances
```

Useful for testing.

#### `clearReflectionCache(): void`

Clear cached reflection metadata:

```php
$container->clearReflectionCache();
```

Useful for debugging or dynamic code generation scenarios.

## Error Handling

The container provides detailed error messages to help with debugging:

### Missing Type Hints

```php
class Service
{
    public function __construct($dependency) {} // No type hint!
}

$container->get(Service::class);
// ContainerException: Cannot resolve "Service" - constructor parameter 
// "dependency" is missing a type hint. Add a type declaration or use 
// a factory function for explicit wiring.
```

**Solution:** Add a type hint or use a factory function.

### Union Types

```php
class Service
{
    public function __construct(TypeA|TypeB $dependency) {}
}

$container->get(Service::class);
// ContainerException: Cannot resolve "Service" - constructor parameter 
// "dependency" has a union type. Union types are ambiguous for automatic 
// resolution. Use a factory function instead.
```

**Solution:** Use a factory function to explicitly choose which type to inject.

### Circular Dependencies

```php
class ServiceA { public function __construct(ServiceB $b) {} }
class ServiceB { public function __construct(ServiceA $a) {} }

$container->get(ServiceA::class);
// CircularDependencyException: Circular dependency detected: 
// ServiceA -> ServiceB -> ServiceA
```

**Solution:** Break the circular dependency or use lazy-loading / property injection.

### Built-in Type Without Default

```php
class Service
{
    public function __construct(string $name) {} // No default value
}

$container->get(Service::class);
// ContainerException: Cannot resolve "Service" - constructor parameter 
// "name" is a builtin type with no default value. Use a factory function 
// for custom wiring.
```

**Solution:** Provide a default value or use a factory function.

## Testing

The package includes a comprehensive test suite:

```bash
# Run tests
composer test

# Generate coverage report
composer test:coverage

# Static analysis
composer phpstan

# Code quality checks
composer lint
composer lint:fix

# Code modernization suggestions
composer rector:check
composer rector:fix
```

## Performance Considerations

### Reflection Caching

The container caches reflection metadata after first resolution:

```php
// First call: reflection overhead
$service = $container->get(Service::class);

// Subsequent calls: cached reflection
$service2 = $container->get(Service::class);
```

Clear the cache if working with dynamically generated classes:

```php
$container->clearReflectionCache();
```

### Singleton Pattern

Use singletons for expensive-to-create services:

```php
// Good - database connection created once
$container->singleton(PDOConnection::class, function($c) {
    return new PDO('mysql:host=localhost', 'user', 'pass');
});

// Poor - creates new connection for every request
$container->transient(PDOConnection::class, PDOConnection::class);
```

## PSR-11 Compliance

This container implements the `Psr\Container\ContainerInterface` and follows PSR-11 standards:

- Implements `get(string $id): mixed`
- Implements `has(string $id): bool`
- Throws `Psr\Container\NotFoundExceptionInterface` when services aren't found
- Throws `Psr\Container\ContainerExceptionInterface` for container errors

## Best Practices

1. **Use constructor dependency injection** - Prefer constructor parameters over setter injection
2. **Type hint everything** - Enable automatic wiring with proper type hints
3. **Use interfaces for bindings** - Makes code more testable and maintainable
4. **Register at bootstrap** - Set up bindings in a single bootstrap file
5. **Use factories for complex logic** - When automatic wiring isn't enough
6. **Avoid circular dependencies** - Refactor to break cycles or use lazy-loading
7. **Test with `clearSingletons()`** - Reset state between tests

## License

MIT License - see [LICENSE](LICENSE) file

## Contributing

Contributions are welcome! Please ensure all tests pass and code meets PHPStan level max:

```bash
composer test
composer phpstan
composer lint:fix
```

---

**Need Help?** Check the [test suite](tests/) for more examples and edge cases.