# Handler API Reference

## CommandHandler Attribute

Source: `Ecotone\Modelling\Attribute\CommandHandler`

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

Source: `Ecotone\Modelling\Attribute\EventHandler`

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class EventHandler extends InputOutputEndpointAnnotation
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
- `routingKey` (string) -- for `listenTo` routing: `#[EventHandler('order.*')]`
- `endpointId` (string) -- unique identifier
- `outputChannelName` (string) -- channel for output
- `dropMessageOnNotFound` (bool) -- drop if aggregate not found
- `identifierMetadataMapping` (array) -- map metadata to aggregate identifier
- `requiredInterceptorNames` (array) -- interceptors to apply
- `identifierMapping` (array) -- map event properties to aggregate identifier

## QueryHandler Attribute

Source: `Ecotone\Modelling\Attribute\QueryHandler`

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

## ServiceActivator Attribute

Source: `Ecotone\Messaging\Attribute\ServiceActivator`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class ServiceActivator extends InputOutputEndpointAnnotation
{
    public function __construct(
        string $inputChannelName = '',
        string $endpointId = '',
        string $outputChannelName = '',
        array $requiredInterceptorNames = [],
        bool $changingHeaders = false
    )
}
```

Parameters:
- `inputChannelName` (string, required) -- channel to consume from
- `endpointId` (string) -- unique identifier
- `outputChannelName` (string) -- channel to send result to
- `requiredInterceptorNames` (array) -- interceptors to apply
- `changingHeaders` (bool) -- whether this changes message headers

## Header Parameter Attribute

Source: `Ecotone\Messaging\Attribute\Parameter\Header`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Header
{
    public function __construct(
        private string $headerName = '',
        private string $expression = ''
    )
}
```

Parameters:
- `headerName` (string) -- name of the message header to extract
- `expression` (string) -- SpEL expression to evaluate on the header value
