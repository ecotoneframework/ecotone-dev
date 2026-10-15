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

`#[After]` only fires when the handler returns a value, so this uses a `#[QueryHandler]` (not the more common
`void` `#[CommandHandler]`) to exercise the full chain, injecting a shared `#[Reference]` service instead of a
captured-by-value local variable (an anonymous class has no implicit constructor):

```php
public function test_interceptor_execution_order(): void
{
    $callStack = new CallStack();

    $handler = new class {
        #[QueryHandler('getOrder')]
        public function handle(): string
        {
            return 'order-data';
        }
    };

    $interceptors = new class {
        #[Before(pointcut: QueryHandler::class)]
        public function before(#[Reference] CallStack $stack): void
        {
            $stack->calls[] = 'before';
        }

        #[Around(pointcut: QueryHandler::class)]
        public function around(MethodInvocation $invocation, #[Reference] CallStack $stack): mixed
        {
            $stack->calls[] = 'around-start';
            $result = $invocation->proceed();
            $stack->calls[] = 'around-end';
            return $result;
        }

        #[After(pointcut: QueryHandler::class)]
        public function after(#[Reference] CallStack $stack): void
        {
            $stack->calls[] = 'after';
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $interceptors::class],
        containerOrAvailableServices: [$handler, $interceptors, $callStack],
    );

    $ecotone->sendQueryWithRouting('getOrder');

    $this->assertEquals(['before', 'around-start', 'around-end', 'after'], $callStack->calls);
}
```

`CallStack` is a small named fixture class (`public array $calls = [];`) referenced by `#[Reference]` -- an
anonymous class here since it is only a type declaration, not the fixture under test.

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

    $ecotone->sendCommandWithRouting('process');

    $this->assertEquals('interceptor', $handler->receivedHeaders['enrichedBy']);
}
```

## Key Testing Notes

- Always register interceptor classes in both `classesToResolve` (for discovery) and `containerOrAvailableServices` (for instantiation)
- Use anonymous classes with public state properties (like `$called`, `$receivedHeaders`) to verify interceptor behavior
- The execution order is: Presend -> Before -> Around (start) -> handler -> Around (end) -> After
