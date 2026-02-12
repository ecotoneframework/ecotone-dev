# Interceptor Testing Patterns

## Basic Interceptor Test

Register both the interceptor and handler in `classesToResolve` and `containerOrAvailableServices`:

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

## Testing Execution Order

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
    // Expected order: before -> around-start -> handler -> around-end -> after
}
```

## Testing Header Modification

```php
public function test_interceptor_modifies_headers(): void
{
    $interceptor = new class {
        #[Before(changeHeaders: true, pointcut: CommandHandler::class)]
        public function enrich(): array
        {
            return ['enrichedBy' => 'interceptor'];
        }
    };

    $handler = new class {
        public array $receivedHeaders = [];

        #[CommandHandler('process')]
        public function handle(#[Headers] array $headers): void
        {
            $this->receivedHeaders = $headers;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $interceptor::class],
        containerOrAvailableServices: [$handler, $interceptor],
    );

    $ecotone->sendCommandWithRoutingKey('process');

    $this->assertEquals('interceptor', $handler->receivedHeaders['enrichedBy']);
}
```

## Key Testing Notes

- Always register interceptor classes in both `classesToResolve` (for discovery) and `containerOrAvailableServices` (for instantiation)
- Use anonymous classes with public state properties (like `$called`, `$receivedHeaders`) to verify interceptor behavior
- The execution order is: Presend -> Before -> Around (start) -> handler -> Around (end) -> After
