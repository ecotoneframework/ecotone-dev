# Handler API Reference

## CommandHandler Attribute

Source: `Ecotone\Api\Attribute\CommandHandler`

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class CommandHandler extends InputOutputEndpointAnnotation
{
    public function __construct(
        string $routingKey = '',
        string $endpointId = '',
        string $outputChannelName = '',
        bool $dropMessageOnNotFound = false,
        array $identifierMetadataMapping = [],
        array $requiredInterceptorNames = [],
        array $identifierMapping = []
    )
}
```

Parameters:
- `routingKey` (string) -- for string-based routing: `#[CommandHandler('order.place')]`
- `endpointId` (string) -- unique identifier for this endpoint
- `outputChannelName` (string) -- channel to send result to
- `dropMessageOnNotFound` (bool) -- drop instead of throwing if aggregate not found
- `identifierMetadataMapping` (array) -- map metadata to aggregate identifier
- `requiredInterceptorNames` (array) -- interceptors to apply
- `identifierMapping` (array) -- map command properties to aggregate identifier

## EventHandler Attribute

Source: `Ecotone\Api\Attribute\EventHandler`

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class EventHandler extends IdentifiedAnnotation
{
    public function __construct(
        string $listenTo = '',
        string $endpointId = '',
        string $outputChannelName = '',
        bool $dropMessageOnNotFound = false,
        array $identifierMetadataMapping = [],
        array $requiredInterceptorNames = [],
        array $identifierMapping = []
    )
}
```

Parameters:
- `listenTo` (string) -- event name to listen to, single (`'order.placed'`) or wildcard (`'order.*'`)
- `endpointId` (string) -- unique identifier
- `outputChannelName` (string) -- channel for output
- `dropMessageOnNotFound` (bool) -- drop if aggregate not found
- `identifierMetadataMapping` (array) -- map metadata to aggregate identifier
- `requiredInterceptorNames` (array) -- interceptors to apply
- `identifierMapping` (array) -- map event properties to aggregate identifier

## QueryHandler Attribute

Source: `Ecotone\Api\Attribute\QueryHandler`

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class QueryHandler extends InputOutputEndpointAnnotation
{
    public function __construct(
        string $routingKey = '',
        string $endpointId = '',
        string $outputChannelName = '',
        array $requiredInterceptorNames = []
    )
}
```

Parameters:
- `routingKey` (string) -- for string-based routing: `#[QueryHandler('order.get')]`
- `endpointId` (string) -- unique identifier
- `outputChannelName` (string) -- channel for output
- `requiredInterceptorNames` (array) -- interceptors to apply

## InternalHandler Attribute

Source: `Ecotone\Api\Attribute\InternalHandler`. Low-level handler for framework-internal message routing that is
not exposed via a bus (`#[ServiceActivator]` was removed in 2.0 -- this is the only such attribute now).

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class InternalHandler extends InputOutputEndpointAnnotation
{
    public function __construct(
        string $inputChannelName,
        string $outputChannelName = '',
        string $endpointId = '',
        array $requiredInterceptorNames = [],
        bool $changingHeaders = false,
        bool $requiresReply = false,
    )
}
```

Parameters:
- `inputChannelName` (string, required) -- channel to consume from
- `outputChannelName` (string) -- channel to send result to -- **positional argument 2**, not `endpointId`
- `endpointId` (string) -- unique identifier -- must be passed as a named argument if `outputChannelName` is not also given positionally
- `requiredInterceptorNames` (array) -- interceptors to apply
- `changingHeaders` (bool) -- whether this changes message headers; `true` requires an Enterprise licence
- `requiresReply` (bool) -- whether the handler must produce a reply

## Header Parameter Attribute

Source: `Ecotone\Api\Attribute\Header`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Header
{
    public function __construct(
        string $headerName,
        string|Closure $expression = ''
    )
}
```

Parameters:
- `headerName` (string, required) -- name of the message header to extract
- `expression` (string|Closure) -- SpEL expression, or closure, evaluated on the header value
