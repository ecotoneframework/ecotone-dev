# Interceptor Patterns Reference

## Attribute Definitions

### Before

Source: `Ecotone\Messaging\Attribute\Interceptor\Before`

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

### After

Source: `Ecotone\Messaging\Attribute\Interceptor\After`

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

### Around

Source: `Ecotone\Messaging\Attribute\Interceptor\Around`

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

### Presend

Source: `Ecotone\Messaging\Attribute\Interceptor\Presend`

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

## Real Example: Transaction Interceptor

Source: `Ecotone\Messaging\Transaction\TransactionInterceptor`

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

## Before Interceptor Example: Validation

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

## After Interceptor Example: Audit Logging

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

## Presend Interceptor Example: Authorization

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

## Header Modification Example

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

## Around with Argument Modification

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

## MethodInvocation Interface

Source: `Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation`

```php
interface MethodInvocation
{
    public function proceed(): mixed;
    public function getArguments(): array;
    public function replaceArgument(string $parameterName, mixed $value): void;
    public function getObjectToInvokeOn(): object;
}
```

## Testing Interceptor Execution Order

```php
public function test_interceptor_execution_order(): void
{
    $callStack = [];

    $beforeInterceptor = new class($callStack) {
        #[Before(pointcut: CommandHandler::class)]
        public function before(): void { $this->stack[] = 'before'; }
    };

    $aroundInterceptor = new class($callStack) {
        #[Around(pointcut: CommandHandler::class)]
        public function around(MethodInvocation $invocation): mixed {
            $this->stack[] = 'around-start';
            $result = $invocation->proceed();
            $this->stack[] = 'around-end';
            return $result;
        }
    };

    $afterInterceptor = new class($callStack) {
        #[After(pointcut: CommandHandler::class)]
        public function after(): void { $this->stack[] = 'after'; }
    };

    // Register all in bootstrapFlowTesting
    // Expected order: before → around-start → handler → around-end → after
}
```
