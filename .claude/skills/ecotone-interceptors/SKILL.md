---
name: ecotone-interceptors
description: >-
  Implements Ecotone interceptors and middleware: #[Before], #[After],
  #[Around], #[Presend] attributes with pointcut targeting, precedence
  ordering, header modification, and MethodInvocation. Use when adding
  interceptors, middleware, cross-cutting concerns like transactions/
  logging/authorization, hooking into handler execution, or modifying
  messages before/after handling.
---

# Ecotone Interceptors

## Overview

Interceptors are cross-cutting middleware that hook into handler execution. Use them for transactions, authorization, logging, validation, header enrichment, and other concerns that span multiple handlers.

| Attribute | When | Flow Control | changeHeaders |
|-----------|------|-------------|---------------|
| `#[Presend]` | Before message enters channel | No | Yes |
| `#[Before]` | Before handler executes | No | Yes |
| `#[Around]` | Wraps handler execution | `MethodInvocation::proceed()` | No |
| `#[After]` | After handler completes | No | Yes |

Execution order: Presend -> Before -> Around -> handler -> Around end -> After

## Before Interceptor

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;

class ValidationInterceptor
{
    #[Before(pointcut: CommandHandler::class)]
    public function validate(object $command): void
    {
        // Throw exception to stop execution
    }
}
```

## After Interceptor

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

## Around Interceptor

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

**You must call `proceed()`** or the handler chain stops.

## Presend Interceptor

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

## Pointcut System

Pointcuts target which handlers an interceptor applies to:

```php
// By attribute
#[Before(pointcut: CommandHandler::class)]

// By class
#[Before(pointcut: OrderService::class)]

// By method
#[Before(pointcut: OrderService::class . '::placeOrder')]

// By namespace
#[Before(pointcut: 'App\Domain\*')]

// AND / OR / NOT
#[Before(pointcut: CommandHandler::class . '||' . EventHandler::class)]
#[Around(pointcut: CommandHandler::class . '&&not(' . WithoutTransaction::class . ')')]
```

### Auto-Inference

When no explicit pointcut is set, it's inferred from the interceptor method's parameter type-hints:

```php
#[Before]
public function check(RequiresAuth $attribute): void { }
// Auto-targets handlers with #[RequiresAuth]
```

## Header Modification

```php
#[Before(changeHeaders: true, pointcut: CommandHandler::class)]
public function addHeaders(#[Headers] array $headers): array
{
    $headers['processedAt'] = time();
    return $headers;
}
```

Only available on `#[Before]`, `#[After]`, `#[Presend]` (not `#[Around]`).

## Key Rules

- Always call `proceed()` in `#[Around]` interceptors
- Use `Precedence::DEFAULT_PRECEDENCE` for custom interceptors
- Pointcuts can target attributes, classes, or interfaces
- Register interceptor classes in `classesToResolve` for testing
- Lower precedence value = runs earlier

## Additional resources

- [API Reference](references/api-reference.md) — Constructor signatures and parameter details for `#[Before]`, `#[After]`, `#[Around]`, `#[Presend]` attributes, `MethodInvocation` interface, and `Precedence` constants table. Load when you need exact parameter names, types, defaults, or precedence values.
- [Usage Examples](references/usage-examples.md) — Full interceptor class implementations: transaction wrappers, validation, audit logging, authorization, correlation ID enrichment, argument modification via `MethodInvocation`, and complete pointcut patterns (attribute, class, namespace, method, AND/OR/NOT, bus targeting, custom attributes, dynamic pointcut building). Load when you need complete, copy-paste-ready interceptor implementations or complex pointcut expressions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test setup for interceptors: verifying interceptor execution, testing execution order (before/around/after), and registering interceptors with `classesToResolve`. Load when writing tests for interceptors.
