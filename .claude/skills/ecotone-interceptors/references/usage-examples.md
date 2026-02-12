# Interceptor Usage Examples

## Transaction Interceptor (Around)

Source pattern: `Ecotone\Messaging\Transaction\TransactionInterceptor`

```php
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Precedence;

class TransactionInterceptor
{
    #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE)]
    public function transactional(MethodInvocation $methodInvocation): mixed
    {
        $transaction = $this->transactionFactory->begin();
        try {
            $result = $methodInvocation->proceed();
            $transaction->commit();
            return $result;
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
    }
}
```

## Validation Interceptor (Before)

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Modelling\Attribute\CommandHandler;

class ValidationInterceptor
{
    #[Before(pointcut: CommandHandler::class, precedence: Precedence::DEFAULT_PRECEDENCE)]
    public function validate(object $payload): void
    {
        $violations = $this->validator->validate($payload);
        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }
    }
}
```

## Audit Logging Interceptor (After)

```php
use Ecotone\Messaging\Attribute\Interceptor\After;
use Ecotone\Messaging\Attribute\Parameter\Header;

class AuditInterceptor
{
    #[After(pointcut: CommandHandler::class)]
    public function audit(
        object $payload,
        #[Header('correlationId')] string $correlationId
    ): void {
        $this->auditLog->record($correlationId, $payload);
    }
}
```

## Authorization Interceptor (Presend)

```php
use Ecotone\Messaging\Attribute\Interceptor\Presend;
use Ecotone\Messaging\Attribute\Parameter\Header;

class AuthorizationInterceptor
{
    #[Presend(pointcut: CommandHandler::class)]
    public function authorize(
        object $payload,
        #[Header('userId')] ?string $userId = null
    ): void {
        if ($userId === null) {
            throw new UnauthorizedException('User not authenticated');
        }
        if (! $this->authService->isAuthorized($userId, $payload::class)) {
            throw new ForbiddenException('User not authorized');
        }
    }
}
```

## Correlation ID Enrichment (Before with changeHeaders)

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;

class CorrelationIdInterceptor
{
    #[Before(changeHeaders: true, pointcut: CommandHandler::class)]
    public function addCorrelationId(#[Headers] array $headers): array
    {
        if (! isset($headers['correlationId'])) {
            $headers['correlationId'] = Uuid::uuid4()->toString();
        }
        return $headers;
    }
}
```

## Header Enrichment (Before with changeHeaders)

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

## Argument Modification (Around)

```php
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class EnrichmentInterceptor
{
    #[Around(pointcut: CommandHandler::class)]
    public function enrich(MethodInvocation $invocation): mixed
    {
        $args = $invocation->getArguments();
        // Modify arguments before handler runs
        $invocation->replaceArgument('timestamp', time());
        return $invocation->proceed();
    }
}
```

## Pointcut Patterns

### Attribute Pointcut

Targets all handlers annotated with a specific attribute:

```php
#[Before(pointcut: CommandHandler::class)]
#[Before(pointcut: EventHandler::class)]
#[Before(pointcut: QueryHandler::class)]
#[Around(pointcut: AsynchronousRunningEndpoint::class)]
```

### Class/Interface Pointcut

Targets all handlers within a specific class or implementing an interface:

```php
#[Before(pointcut: OrderService::class)]
#[Around(pointcut: CommandBus::class)]
#[Around(pointcut: QueryBus::class)]
#[Around(pointcut: EventBus::class)]
#[Around(pointcut: Gateway::class)]
```

### Namespace Pointcut

Targets classes matching a wildcard pattern:

```php
#[Before(pointcut: 'App\Domain\*')]
#[Before(pointcut: 'App\Order\Handlers\*')]
#[Before(pointcut: 'App\*\Handlers\OrderHandler')]
```

### Method Pointcut

Targets a specific method in a specific class:

```php
#[Before(pointcut: OrderService::class . '::placeOrder')]
#[Around(pointcut: PaymentService::class . '::processPayment')]
```

### Negation

Excludes specific targets:

```php
#[Around(pointcut: CommandHandler::class . '&&not(' . WithoutTransaction::class . ')')]
#[Around(pointcut: CommandHandler::class . '&&not(' . ProjectingConsoleCommands::class . '::backfillProjection)')]
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

### Real-World Example: Dynamic Transaction Pointcut

```php
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

### Auto-Inference from Parameter Types

When `pointcut` is empty (default), the framework infers targeting from the interceptor method's parameter type-hints:

```php
// Auto-targets all handlers with #[RateLimit] attribute
#[Before]
public function limit(RateLimit $rateLimit): void
{
    // $rateLimit is the attribute instance from the handler
}

// Multiple attributes: nullable = OR, non-nullable = AND
#[Before]
public function check(?FeatureA $a, RequiresAuth $auth): void { }
// Equivalent to: (FeatureA)&&RequiresAuth

// Targets handlers that receive PlaceOrder as payload
#[Before]
public function beforePlaceOrder(PlaceOrder $command): void { }
```

### Custom Attribute as Pointcut

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

### Common Pointcut Patterns Summary

| Use Case | Pointcut |
|----------|----------|
| All write operations | `CommandHandler::class` |
| All message handlers | `CommandHandler::class . '\|\|' . EventHandler::class . '\|\|' . QueryHandler::class` |
| Specific aggregate | `Order::class` |
| Async handlers only | `Asynchronous::class` |
| All bus calls | `Gateway::class` |
| Exclude opt-outs | `CommandHandler::class . '&&not(' . WithoutTransaction::class . ')'` |
