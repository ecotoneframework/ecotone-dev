# Workflow API Reference

## #[Saga] Attribute

Source: `Ecotone\Api\Attribute\Saga`

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

Source: `Ecotone\Api\Attribute\EventSourcingSaga`

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

Source: `Ecotone\Api\Attribute\InternalHandler`

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
- `changingHeaders` (bool, optional, default `false`) -- header-changer mode, see below. **Requires Ecotone Enterprise licence.**

By default (`changingHeaders: false`), if the handler returns `null`, the chain stops (no message sent to outputChannel).

### Header-changer mode (`changingHeaders: true`, Enterprise)

Source: `Ecotone\Api\Attribute\ServiceActivator` / `Ecotone\Api\Attribute\InternalHandler`

With `changingHeaders: true`, the handler's return value is treated as headers to merge, not as the new payload:

```php
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Header;

class EnrichWithTenant
{
    #[InternalHandler(inputChannelName: 'step.name', outputChannelName: 'next.step', changingHeaders: true)]
    public function enrich(#[Header('tenantId')] ?string $tenantId): array
    {
        return ['tenantId' => $tenantId ?? $this->tenantResolver->resolveCurrentTenantId()];
    }
}
```

- The returned `array` is merged into the message headers (existing keys are overwritten, other headers preserved); the payload is left untouched.
- Returning `null` leaves the message (payload and headers) unchanged.
- Returning anything other than `array` or `null` throws `Ecotone\Messaging\Support\InvalidArgumentException` naming the class/method.
- The (unchanged-payload, enriched-headers) message continues to `outputChannelName` exactly as in the default mode.
- `changingHeaders: true` requires an Ecotone Enterprise licence; without one, bootstrap throws `Ecotone\Messaging\Support\LicensingException` naming the class/method and linking to https://docs.ecotone.tech/enterprise. `changingHeaders: false` (the default) has no licence requirement.

## #[Orchestrator] Attribute (Enterprise)

Source: `Ecotone\Api\Attribute\Orchestrator`

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

Source: `Ecotone\Api\Attribute\OrchestratorGateway`

Method-level attribute on interface methods. Creates business interface gateway.

```php
use Ecotone\Api\Attribute\OrchestratorGateway;

interface MyWorkflowProcess
{
    #[OrchestratorGateway('workflow.start')]
    public function start(mixed $data): mixed;
}
```

Parameters:
- First argument (string, required) -- the input channel name of the orchestrator to invoke
