# Interceptors API Reference

## `#[Before]`

Source: `Ecotone\Messaging\Attribute\Interceptor\Before`

Runs before the handler executes. Can modify the payload, validate, or throw to abort.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class Before
{
    public function __construct(
        int $precedence = Precedence::DEFAULT_PRECEDENCE,
        string $pointcut = '',
        bool $changeHeaders = false
    )
}
```

**Parameters:**
- `precedence` (int, default `Precedence::DEFAULT_PRECEDENCE` = 1) — Execution order. Lower runs earlier.
- `pointcut` (string, default `''`) — Pointcut expression targeting handlers. Empty = auto-inferred from parameter types.
- `changeHeaders` (bool, default `false`) — When `true`, the interceptor must return an `array` that gets merged into message headers.

## `#[After]`

Source: `Ecotone\Messaging\Attribute\Interceptor\After`

Runs after the handler completes. Receives the handler's return value as first parameter.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class After
{
    public function __construct(
        int $precedence = Precedence::DEFAULT_PRECEDENCE,
        string $pointcut = '',
        bool $changeHeaders = false
    )
}
```

**Parameters:** Same as `#[Before]`.

## `#[Around]`

Source: `Ecotone\Messaging\Attribute\Interceptor\Around`

Wraps handler execution. Must call `MethodInvocation::proceed()` to continue the chain.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class Around
{
    public function __construct(
        int $precedence = Precedence::DEFAULT_PRECEDENCE,
        string $pointcut = ''
    )
}
```

**Parameters:**
- `precedence` (int, default `Precedence::DEFAULT_PRECEDENCE` = 1) — Execution order. Lower runs earlier.
- `pointcut` (string, default `''`) — Pointcut expression targeting handlers.

Note: `#[Around]` does NOT support `changeHeaders`.

## `#[Presend]`

Source: `Ecotone\Messaging\Attribute\Interceptor\Presend`

Runs before the message enters the channel (before `#[Before]`). Useful for authorization or enrichment before async dispatch.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class Presend
{
    public function __construct(
        int $precedence = Precedence::DEFAULT_PRECEDENCE,
        string $pointcut = '',
        bool $changeHeaders = false
    )
}
```

**Parameters:** Same as `#[Before]`.

## `MethodInvocation` Interface

Source: `Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation`

Used exclusively in `#[Around]` interceptors to control handler execution.

```php
interface MethodInvocation
{
    public function proceed(): mixed;
    public function getArguments(): array;
    public function replaceArgument(string $parameterName, mixed $value): void;
    public function getObjectToInvokeOn(): object;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `proceed()` | `mixed` | Continue to next interceptor or handler. **Must be called.** |
| `getArguments()` | `array` | Get handler method arguments as named array |
| `replaceArgument(string $name, $value)` | `void` | Replace a handler argument before proceeding |
| `getObjectToInvokeOn()` | `object` | Get the handler instance being invoked |

## Precedence Constants

Source: `Ecotone\Messaging\Precedence`

| Constant | Value | Purpose |
|----------|-------|---------|
| `ENDPOINT_HEADERS_PRECEDENCE` | -3000 | Headers setup |
| `CUSTOM_INSTANT_RETRY_PRECEDENCE` | -2003 | Custom retry |
| `GLOBAL_INSTANT_RETRY_PRECEDENCE` | -2002 | Global retry |
| `DATABASE_TRANSACTION_PRECEDENCE` | -2000 | Database transactions |
| `LAZY_EVENT_PUBLICATION_PRECEDENCE` | -1900 | Event publishing |
| `DEFAULT_PRECEDENCE` | 1 | Default for custom interceptors |

Lower value = runs earlier (wraps the handler further out).

## Pointcut Expression Syntax Summary

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

## Pointcut Expression Internal Classes

Source: `Ecotone\Messaging\Handler\Processor\MethodInvoker\Pointcut\`

| Class | Purpose |
|-------|---------|
| `PointcutAttributeExpression` | Match by attribute on the handler method |
| `PointcutInterfaceExpression` | Match by class/interface of the handler |
| `PointcutMethodExpression` | Match by specific method name |
| `PointcutOrExpression` | Logical OR of two expressions |
| `PointcutAndExpression` | Logical AND of two expressions |
| `PointcutNotExpression` | Logical NOT of an expression |
