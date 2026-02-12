# Workflow API Reference

## #[Saga] Attribute

Source: `Ecotone\Modelling\Attribute\Saga`

Class-level attribute. Extends `Aggregate` -- sagas are stored and loaded like aggregates.

```php
#[Saga]
class MyProcess
{
    #[Identifier]
    private string $processId;
}
```

## #[EventSourcingSaga] Attribute

Source: `Ecotone\Modelling\Attribute\EventSourcingSaga`

Class-level attribute. Extends `EventSourcingAggregate` -- saga state rebuilt from events.

```php
#[EventSourcingSaga]
class MyProcess
{
    use WithEvents;

    #[Identifier]
    private string $processId;
}
```

## WithEvents Trait

Source: `Ecotone\Modelling\WithEvents`

```php
use Ecotone\Modelling\WithEvents;

#[Saga]
class OrderProcess
{
    use WithEvents;

    public function handle(SomeEvent $event): void
    {
        $this->recordThat(new SomethingHappened($this->id));
    }
}
```

Methods:
- `recordThat(object $event)` -- records a domain event to be published after handler completes
- Events are auto-cleared after publishing

## #[InternalHandler] Attribute

Source: `Ecotone\Messaging\Attribute\InternalHandler`

Extends `ServiceActivator`. For internal message routing not exposed via bus.

```php
#[InternalHandler(
    inputChannelName: 'step.name',      // required -- channel to listen on
    outputChannelName: 'next.step',     // optional -- chain to next handler
    endpointId: 'step.endpoint',        // optional -- required with #[Asynchronous]
    requiredInterceptorNames: [],       // optional -- interceptors to apply
    changingHeaders: false,             // optional -- whether handler modifies headers
)]
public function handle(mixed $payload): mixed { }
```

Parameters:
- `inputChannelName` (string, required) -- internal channel to listen on
- `outputChannelName` (string, optional) -- channel to send result to (chains to next step)
- `endpointId` (string, optional) -- required when used with `#[Asynchronous]`
- `requiredInterceptorNames` (array, optional) -- interceptors to apply
- `changingHeaders` (bool, optional) -- whether handler modifies message headers

If handler returns `null`, the chain stops (no message sent to outputChannel).

## #[Orchestrator] Attribute (Enterprise)

Source: `Ecotone\Messaging\Attribute\Orchestrator`

Method-level attribute. Returns array of channel names (routing slip).

```php
#[Orchestrator(
    inputChannelName: 'workflow.start',  // required -- trigger channel
    endpointId: 'my-orchestrator',       // optional -- required with #[Asynchronous]
)]
public function start(): array
{
    return ['step1', 'step2', 'step3'];
}
```

Parameters:
- `inputChannelName` (string, required) -- channel that triggers the orchestrator
- `endpointId` (string, optional) -- required when used with `#[Asynchronous]`

## #[OrchestratorGateway] Attribute (Enterprise)

Source: `Ecotone\Messaging\Attribute\OrchestratorGateway`

Method-level attribute on interface methods. Creates business interface gateway.

```php
use Ecotone\Messaging\Attribute\OrchestratorGateway;

interface MyWorkflowProcess
{
    #[OrchestratorGateway('workflow.start')]
    public function start(mixed $data): mixed;
}
```

Parameters:
- First argument (string, required) -- the input channel name of the orchestrator to invoke
