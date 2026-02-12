# Pointcut Expression Reference

Pointcuts determine which handlers an interceptor targets.

## Expression Types

Source: `Ecotone\Messaging\Handler\Processor\MethodInvoker\Pointcut\`

### By Attribute

Targets all handler methods annotated with a specific attribute:

```php
#[Before(pointcut: CommandHandler::class)]       // All #[CommandHandler] methods
#[Before(pointcut: EventHandler::class)]          // All #[EventHandler] methods
#[Before(pointcut: QueryHandler::class)]          // All #[QueryHandler] methods
#[Before(pointcut: Asynchronous::class)]          // All #[Asynchronous] methods
```

Custom attributes work too:

```php
#[Before(pointcut: RequiresAuth::class)]          // All methods with #[RequiresAuth]
#[Before(pointcut: Auditable::class)]             // All methods with #[Auditable]
```

### By Class/Interface

Targets all handler methods in a specific class or implementing interface:

```php
#[Before(pointcut: OrderService::class)]          // All handlers in OrderService
#[Before(pointcut: HasAuditTrail::class)]         // All handlers in classes implementing HasAuditTrail
```

### Logical Operators

**AND** — both conditions must match:

```php
#[Before(pointcut: CommandHandler::class . '&&' . Auditable::class)]
```

**OR** — either condition matches:

```php
#[Before(pointcut: CommandHandler::class . '||' . EventHandler::class)]
```

Operators are string-based: `'&&'` and `'||'`.

## Auto-Inference

When `pointcut` is empty (default), the framework infers targeting from the interceptor method's parameter type-hints.

### Attribute Parameter Inference

If the interceptor accepts a custom attribute as a parameter, it targets all handlers annotated with that attribute:

```php
class RateLimitInterceptor
{
    // Automatically targets all handlers with #[RateLimit] attribute
    #[Before]
    public function limit(RateLimit $rateLimit): void
    {
        // $rateLimit is the attribute instance from the handler
    }
}
```

### Payload Type Inference

If the interceptor accepts a specific message type, it targets handlers that process that type:

```php
class OrderInterceptor
{
    // Targets handlers that receive PlaceOrder as payload
    #[Before]
    public function beforePlaceOrder(PlaceOrder $command): void { }
}
```

## Pointcut Expression Classes

Internal classes that implement the pointcut matching:

| Class | Purpose |
|-------|---------|
| `PointcutAttributeExpression` | Match by attribute on the handler method |
| `PointcutInterfaceExpression` | Match by class/interface of the handler |
| `PointcutMethodExpression` | Match by specific method name |
| `PointcutOrExpression` | Logical OR of two expressions |
| `PointcutAndExpression` | Logical AND of two expressions |
| `PointcutNotExpression` | Logical NOT of an expression |

## Common Pointcut Patterns

### Target all write operations

```php
#[Before(pointcut: CommandHandler::class)]
```

### Target all message handlers

```php
#[Before(pointcut: CommandHandler::class . '||' . EventHandler::class . '||' . QueryHandler::class)]
```

### Target specific aggregate handlers

```php
#[Before(pointcut: Order::class)]
```

### Target async handlers only

```php
#[Before(pointcut: Asynchronous::class)]
```

### Target handlers with custom attribute

```php
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresAuth
{
    public function __construct(public string $role = 'user') {}
}

// Handler
class OrderService
{
    #[CommandHandler]
    #[RequiresAuth(role: 'admin')]
    public function deleteOrder(DeleteOrder $command): void { }
}

// Interceptor — auto-inferred pointcut from parameter type
class AuthInterceptor
{
    #[Before]
    public function checkAuth(RequiresAuth $attribute, #[Header('userId')] string $userId): void
    {
        if (! $this->auth->hasRole($userId, $attribute->role)) {
            throw new ForbiddenException();
        }
    }
}
```
