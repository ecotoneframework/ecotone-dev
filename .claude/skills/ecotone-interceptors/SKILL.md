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

Pointcuts target which handlers an interceptor applies to. They support attributes, classes, namespaces, methods, and logical operators.

### Attribute Pointcut

Targets all handlers annotated with a specific attribute:

```php
// Targets all methods with #[CommandHandler]
#[Before(pointcut: CommandHandler::class)]

// Targets all methods with #[EventHandler]
#[Before(pointcut: EventHandler::class)]

// Targets all methods with #[QueryHandler]
#[Before(pointcut: QueryHandler::class)]

// Targets asynchronous endpoints
#[Around(pointcut: AsynchronousRunningEndpoint::class)]
```

### Class/Interface Pointcut

Targets all handlers within a specific class or implementing an interface:

```php
// Targets all handlers in OrderService
#[Before(pointcut: OrderService::class)]

// Targets all bus gateway calls (commands sent via CommandBus)
#[Around(pointcut: CommandBus::class)]

// Targets all query bus calls
#[Around(pointcut: QueryBus::class)]

// Targets all event bus calls
#[Around(pointcut: EventBus::class)]

// Targets all gateway calls
#[Around(pointcut: Gateway::class)]
```

### Namespace Pointcut

Targets classes matching a wildcard pattern (`*` matches any characters):

```php
// Targets all handlers in App\Domain namespace and sub-namespaces
#[Before(pointcut: 'App\Domain\*')]

// Targets specific sub-namespace
#[Before(pointcut: 'App\Order\Handlers\*')]

// Wildcard in the middle
#[Before(pointcut: 'App\*\Handlers\OrderHandler')]
```

### Method Pointcut

Targets a specific method in a specific class:

```php
// Targets only the placeOrder method in OrderService
#[Before(pointcut: OrderService::class . '::placeOrder')]

// Targets a specific handler method
#[Around(pointcut: PaymentService::class . '::processPayment')]
```

### Negation

Excludes specific targets:

```php
// Targets all CommandHandlers EXCEPT those with #[WithoutTransaction]
#[Around(pointcut: CommandHandler::class . '&&not(' . WithoutTransaction::class . ')')]

// Excludes a specific method
#[Around(pointcut: CommandHandler::class . '&&not(' . ProjectingConsoleCommands::class . '::backfillProjection)')]

// Excludes a namespace
#[Before(pointcut: 'not(App\Internal\*)')]
```

### Combining with && (AND) and || (OR)

```php
// AND — both must match
#[Before(pointcut: CommandHandler::class . '&&' . AuditableHandler::class)]

// OR — either matches
#[Before(pointcut: CommandHandler::class . '||' . EventHandler::class)]

// Complex: (attribute OR bus) AND NOT excluded
#[Around(pointcut: '(' . CommandHandler::class . '||' . CommandBus::class . ')&&not(' . WithoutTransaction::class . ')')]
```

### Real-World Example: Transaction Module

```php
// Dynamically build pointcut for database transactions
$pointcut = '(' . DbalTransaction::class . ')';
if ($config->isTransactionOnAsynchronousEndpoints()) {
    $pointcut .= '||(' . AsynchronousRunningEndpoint::class . ')';
}
if ($config->isTransactionOnCommandBus()) {
    $pointcut .= '||(' . CommandBus::class . ')';
}
if ($config->isTransactionOnConsoleCommands()) {
    $pointcut .= '||(' . ConsoleCommand::class . ')';
}
// Exclude opt-outs
$pointcut = '(' . $pointcut . ')&&not(' . WithoutDbalTransaction::class . ')';
```

### Auto-Inference

When no explicit pointcut is set, it's inferred from the interceptor method's parameter type-hints:

```php
// Auto-targets handlers that have #[RequiresAuth] attribute
#[Before]
public function check(RequiresAuth $attribute): void { }

// Multiple attributes: nullable = OR, non-nullable = AND
#[Before]
public function check(?FeatureA $a, RequiresAuth $auth): void { }
// Equivalent to: (FeatureA)&&RequiresAuth
```

### Pointcut Summary

| Pattern | Example | Matches |
|---------|---------|---------|
| Attribute | `CommandHandler::class` | Methods with `#[CommandHandler]` |
| Class | `OrderService::class` | All handlers in OrderService |
| Bus | `CommandBus::class` | All command bus gateway calls |
| Namespace | `'App\Domain\*'` | Classes in App\Domain\* |
| Method | `OrderService::class . '::place'` | Specific method |
| AND | `A::class . '&&' . B::class` | Both must match |
| OR | `A::class . '\|\|' . B::class` | Either matches |
| NOT | `'not(' . A::class . ')'` | Excludes matching |

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
