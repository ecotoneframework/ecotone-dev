---
name: ecotone-interceptors
description: >-
  Implements Ecotone interceptors and middleware: #[Before], #[After],
  #[Around], #[Presend] attributes with pointcut targeting, precedence
  ordering, header modification, and MethodInvocation flow control.
  Use when adding interceptors, middleware, cross-cutting concerns,
  hooking into handler execution, or implementing transactions/logging/auth.
---

# Ecotone Interceptors

## 1. Interceptor Types

| Attribute | When | Flow Control | changeHeaders |
|-----------|------|-------------|---------------|
| `#[Presend]` | Before message enters channel | No | Yes |
| `#[Before]` | Before handler executes | No | Yes |
| `#[Around]` | Wraps handler execution | `MethodInvocation::proceed()` | No |
| `#[After]` | After handler completes | No | Yes |

Execution order: Presend → Before → Around → handler → Around end → After

## 2. Before Interceptor

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Precedence;

class ValidationInterceptor
{
    #[Before(precedence: Precedence::DEFAULT_PRECEDENCE, pointcut: CommandHandler::class)]
    public function validate(object $command): void
    {
        // Validate the command before handler runs
        // Throw exception to stop execution
    }
}
```

Parameters: `precedence` (int), `pointcut` (string), `changeHeaders` (bool)

## 3. After Interceptor

```php
use Ecotone\Messaging\Attribute\Interceptor\After;

class AuditInterceptor
{
    #[After(pointcut: CommandHandler::class)]
    public function audit(object $command): void
    {
        // Log after handler completes
    }
}
```

Parameters: `precedence` (int), `pointcut` (string), `changeHeaders` (bool)

## 4. Around Interceptor

```php
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class TransactionInterceptor
{
    #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE)]
    public function transactional(MethodInvocation $invocation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $invocation->proceed();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
```

Parameters: `precedence` (int), `pointcut` (string)

### MethodInvocation API

| Method | Returns | Description |
|--------|---------|-------------|
| `proceed()` | `mixed` | Continue to next interceptor or handler |
| `getArguments()` | `array` | Get handler method arguments |
| `replaceArgument(string $name, $value)` | `void` | Replace argument before proceeding |
| `getObjectToInvokeOn()` | `object` | Get the handler instance |

**You must call `proceed()`** or the handler chain stops.

## 5. Presend Interceptor

```php
use Ecotone\Messaging\Attribute\Interceptor\Presend;

class AuthorizationInterceptor
{
    #[Presend(pointcut: CommandHandler::class)]
    public function authorize(object $command, #[Header('userId')] string $userId): void
    {
        if (! $this->authService->canExecute($userId, $command)) {
            throw new UnauthorizedException();
        }
    }
}
```

Parameters: `precedence` (int), `pointcut` (string), `changeHeaders` (bool)

## 6. Pointcut System

Pointcuts target which handlers an interceptor applies to.

### By Attribute

```php
// Targets all methods with #[CommandHandler]
#[Before(pointcut: CommandHandler::class)]
```

### By Class/Interface

```php
// Targets all handlers in this class
#[Before(pointcut: OrderService::class)]

// Targets all handlers in classes implementing this interface
#[Before(pointcut: AuditableHandler::class)]
```

### Logical Operators

```php
// AND — both must match
#[Before(pointcut: CommandHandler::class . '&&' . AuditableHandler::class)]

// OR — either matches
#[Before(pointcut: CommandHandler::class . '||' . EventHandler::class)]
```

### Auto-Inference

When no explicit pointcut is set, it's inferred from the interceptor method's parameter type-hints:

```php
// Auto-targets handlers that have #[RequiresAuth] attribute
#[Before]
public function check(RequiresAuth $attribute): void { }
```

## 7. Precedence Constants

Source: `Ecotone\Messaging\Precedence`

| Constant | Value | Purpose |
|----------|-------|---------|
| `ENDPOINT_HEADERS_PRECEDENCE` | -3000 | Headers setup |
| `CUSTOM_INSTANT_RETRY_PRECEDENCE` | -2003 | Custom retry |
| `GLOBAL_INSTANT_RETRY_PRECEDENCE` | -2002 | Global retry |
| `DATABASE_TRANSACTION_PRECEDENCE` | -2000 | Database transactions |
| `LAZY_EVENT_PUBLICATION_PRECEDENCE` | -1900 | Event publishing |
| `DEFAULT_PRECEDENCE` | 1 | Default for custom interceptors |

Lower value = runs earlier.

## 8. Header Modification

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;

class HeaderEnricher
{
    #[Before(changeHeaders: true, pointcut: CommandHandler::class)]
    public function addHeaders(
        object $command,
        #[Headers] array $headers
    ): array {
        $headers['processedAt'] = time();
        $headers['version'] = '2.0';
        return $headers;
    }
}
```

Only available on `#[Before]`, `#[After]`, `#[Presend]` (not `#[Around]`).

## 9. Testing Interceptors

```php
public function test_interceptor_runs(): void
{
    $interceptor = new class {
        public bool $called = false;

        #[Before(pointcut: CommandHandler::class)]
        public function intercept(): void
        {
            $this->called = true;
        }
    };

    $handler = new class {
        #[CommandHandler]
        public function handle(PlaceOrder $command): void { }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $interceptor::class],
        containerOrAvailableServices: [$handler, $interceptor],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $this->assertTrue($interceptor->called);
}
```

## Key Rules

- Always call `proceed()` in `#[Around]` interceptors
- Use `Precedence::DEFAULT_PRECEDENCE` for custom interceptors
- Pointcuts can target attributes, classes, or interfaces
- Register interceptor classes in `classesToResolve` for testing
- See `references/interceptor-patterns.md` for real examples
- See `references/pointcut-reference.md` for expression syntax
