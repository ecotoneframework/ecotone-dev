---
name: ecotone-interceptors
description: >-
  Implements Ecotone interceptors and middleware: #[Before], #[After],
  #[Around], #[Presend], #[ChannelInterceptor] attributes with pointcut
  targeting, precedence ordering, header modification, and
  MethodInvocation. Use when adding interceptors, middleware,
  cross-cutting concerns like transactions/logging/authorization, hooking
  into handler execution, or modifying messages before/after handling or
  on a specific channel.
---

# Ecotone Interceptors

## Overview

Interceptors are cross-cutting middleware that hook into handler execution. Use them for transactions, authorization, logging, validation, header enrichment, and other concerns that span multiple handlers.

| Attribute | When | Flow Control | changeHeaders |
|-----------|------|-------------|---------------|
| `#[Presend]` | Before message enters channel, matched by pointcut against handlers | No | Yes |
| `#[Before]` | Before handler executes | No | Yes |
| `#[Around]` | Wraps handler execution | `MethodInvocation::proceed()` | No |
| `#[After]` | After handler completes | No | Yes |
| `#[ChannelInterceptor]` (Enterprise) | Before a message is sent to a named channel | No | Yes |

Execution order: Presend -> Before -> Around -> handler -> Around end -> After

**`#[After]` only runs when the intercepted handler actually returns a value.** A `void` handler produces no
reply for `#[After]` to post-process, so it is skipped entirely: identical `#[Before]`/`#[After]` interceptors on
the same pointcut fire `before` either way, but `after` only when the handler's return type is non-`void`. Target `#[QueryHandler]`s, or handlers with a return type, when using
`#[After]`.

## Applies when

- Wrapping handlers in a transaction, a lock, a timer or any logic that must run around the handler call
- Adding or changing message headers (tenant, user, correlation data) before a handler runs
- Authorizing or validating commands, including ones handled asynchronously, before they are queued
- Running logic only for handlers marked with your own attribute (e.g. `#[RequiresRole]`, `#[Audited]`)
- Ordering several interceptors relative to each other or to Ecotone's own (transactions, retries)

## Before Interceptor

```php
use Ecotone\Api\Attribute\Before;

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

Only fires for a handler that returns a value -- pointing this at `CommandHandler::class` broadly is a no-op for
every `void` command handler.

```php
use Ecotone\Api\Attribute\After;

class AuditInterceptor
{
    #[After(pointcut: QueryHandler::class)]
    public function audit(mixed $result): void
    {
        // Receives the handler's return value as the first parameter; log after handler completes
    }
}
```

## Around Interceptor

```php
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Interceptor\MethodInvocation;

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
use Ecotone\Api\Attribute\Presend;

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

## Channel Interceptor (Enterprise)

`#[ChannelInterceptor]` targets a channel by **name** instead of a pointcut against handlers — it runs for every message sent to that channel, regardless of which handler(s) receive it, including before a message is enqueued to an asynchronous channel.

```php
use Ecotone\Api\Attribute\ChannelInterceptor;

class OrdersChannelInterceptor
{
    #[ChannelInterceptor('orders', changeHeaders: true, precedence: Precedence::DEFAULT_PRECEDENCE)]
    public function intercept(#[Headers] array $headers): array
    {
        $headers['tenantId'] = $this->resolveTenantId();
        return $headers;
    }
}
```

Requires an Ecotone Enterprise licence — bootstrapping with a `#[ChannelInterceptor]` present and no licence key throws `LicensingException` naming the offending class and method. Method parameters use the same converters as `#[Before]`/`#[Presend]` (`Message`, `#[Payload]`, `#[Header]`, `#[Headers]`, `#[Reference]`); `changeHeaders` and `precedence` mean the same thing as elsewhere in this table.

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
#[Around(pointcut: CommandHandler::class . '&&not(' . WithoutDatabaseTransaction::class . ')')]
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

Only available on `#[Before]`, `#[After]`, `#[Presend]`, `#[ChannelInterceptor]` (not `#[Around]`).

## Rules

### 1. An `#[Around]` interceptor calls `proceed()` and returns its result
`MethodInvocation::proceed()` is what runs the next interceptor and the handler; skipping it skips the handler, and
dropping its return value loses the handler's reply (a query result, an aggregate id).
- **Expected:** every `#[Around]` method takes `MethodInvocation`, calls `$invocation->proceed()` on its success path and returns that result (re-throwing after any cleanup on failure).
- **Verified by:** `eval:cross-cutting-interceptors`

### 2. Headers are changed with `changeHeaders: true` on `#[Before]`, `#[Presend]` or `#[After]`, never in `#[Around]`
`#[Around]` has no `changeHeaders` option. With `changeHeaders: true` the returned array is merged into the message
headers; without it, a returned value is treated as the new payload.
- **Expected:** the interceptor that adds a header is `#[Before]`/`#[Presend]`/`#[After]` with `changeHeaders: true`, returns an array of headers, and no `#[Around]` interceptor is used to modify headers.
- **Verified by:** `eval:cross-cutting-interceptors`

### 3. Checks that must stop an asynchronous message before it is queued use `#[Presend]`
`#[Presend]` runs in the sending process before the message enters the handler's channel; `#[Before]` and
`#[Around]` on an `#[Asynchronous]` handler run later, inside the consumer, after the message was already queued.
- **Expected:** an authorization or validation that must reject an asynchronous command at send time is a `#[Presend]` interceptor, not `#[Before]` or `#[Around]`.
- **Verified by:** `eval:cross-cutting-interceptors`

### 4. `#[After]` runs only when the intercepted handler returns a value
A `void` handler produces no reply, so `#[After]` interceptors on it are skipped entirely.
- **Expected:** logic that must run after `void` command handlers is placed in an `#[Around]` interceptor (after `proceed()`), not in `#[After]`; `#[After]` is used only for handlers that return a value.
- **Verified by:** `eval:cross-cutting-interceptors`

### 5. Target only the handlers you mean, by attribute
A pointcut selects handlers by attribute, class, method, namespace or a `&&`/`||`/`not()` expression; with no
pointcut, a parameter typed as an attribute class both targets handlers carrying that attribute and receives its
instance.
- **Expected:** an interceptor meant for handlers marked with a custom attribute uses `pointcut: TheAttribute::class` or a parameter typed with that attribute, not a broad `CommandHandler::class` pointcut with a runtime check.
- **Verified by:** `eval:cross-cutting-interceptors`

### 6. Lower `precedence` runs first
Interceptors of one kind are ordered by ascending `precedence`; the default is `Precedence::DEFAULT_PRECEDENCE` (1),
and Ecotone's own interceptors use the other `Precedence` constants (e.g. `DATABASE_TRANSACTION_PRECEDENCE`, -2000).
- **Expected:** when one interceptor must run before another of the same kind, it is given the lower `precedence` value.
- **Verified by:** `eval:cross-cutting-interceptors`

## Additional resources

- [API Reference](references/api-reference.md) — Constructor signatures and parameter details for `#[Before]`, `#[After]`, `#[Around]`, `#[Presend]` attributes, `MethodInvocation` interface, and `Precedence` constants table. Load when you need exact parameter names, types, defaults, or precedence values.
- [Usage Examples](references/usage-examples.md) — Full interceptor class implementations: transaction wrappers, validation, audit logging, authorization, correlation ID enrichment, argument modification via `MethodInvocation`, and complete pointcut patterns (attribute, class, namespace, method, AND/OR/NOT, bus targeting, custom attributes, dynamic pointcut building). Load when you need complete, copy-paste-ready interceptor implementations or complex pointcut expressions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test setup for interceptors: verifying interceptor execution, testing execution order (before/around/after), and registering interceptors with `classesToResolve`. Load when writing tests for interceptors.
