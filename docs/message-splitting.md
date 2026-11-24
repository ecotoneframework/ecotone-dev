# Split a handler's output

Place `#[Splitter]` next to a service `#[CommandHandler]`, `#[EventHandler]` or `#[InternalHandler]` to send each
returned element as a separate message to that handler's `outputChannelName`.

```php
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;

#[CommandHandler('import.file', outputChannelName: 'import.row')]
#[Splitter]
public function import(ImportFile $command): array
{
    return $this->rows($command->path);
}

#[InternalHandler('import.row')]
public function processRow(array $row): void
{
}
```

Arrays (including keyed arrays), `iterable`, `Generator` and `Traversable` results are split in iteration order;
keys do not become message numbers. An empty result sends no messages. Ordinary values inherit the handled
message's metadata and correlation id;
each part gets its own message id and sequence number. Returned `Message` elements retain their own headers,
as with a standalone splitter. A command caller receives no direct reply from a handler with an output channel.

Each repeated handler attribute uses its own output channel. Every one must define `outputChannelName`.
`#[Asynchronous]` stays on the handler: the handler and its split execute in that consumer run and its existing
transaction. Before, around and after interceptors stay around the handler. Put `requiredInterceptorNames` on
the handler attribute.

Aggregate and saga Command/Event action methods may also split an independent iterable return value, after the
aggregate is saved. Factories are refused because their output contains saved identifiers. Pure event-sourced
actions whose return values are recorded as events are refused; record events through `#[AggregateEvents]`
and return an independent iterable value instead. `#[InternalHandler]` remains a service-only handler.

Only `gatherChannelName` may be set on a stacked `#[Splitter]`. Its input, endpoint id, output and required
interceptors belong to the handler attribute. Missing output channels, non-iterable declared return types,
factory/action pairs sharing a route, header-changing handlers, projection methods, factory methods and
`#[QueryHandler]` combinations are refused at bootstrap with instructions to fix them.

To [gather the parts](message-gathering.md), give the handler an explicit stable `endpointId` and set
`#[Splitter(gatherChannelName: 'import.summary')]`. Splitting is open core; gathering requires Enterprise.
The graph and Pulse show the existing handler as splitting, without an application channel for the internal hop.

## Standalone splitters

A dedicated splitter method remains useful when several handlers share the same splitting step:

```php
#[Splitter('import.split', outputChannelName: 'import.row')]
public function split(array $rows): array
{
    return $rows;
}
```

A standalone splitter owns its input channel, endpoint id, output channel and interceptor names. Its existing
behaviour is unchanged. A standalone gathering splitter requires its own explicit `endpointId`.
