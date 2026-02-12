# Metadata API Reference

## `#[Header]`

Source: `Ecotone\Messaging\Attribute\Parameter\Header`

Extracts a single header from message metadata and injects it into a handler parameter.

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Header
{
    public function __construct(string $headerName, string $expression = '')
}
```

**Parameters:**
- `headerName` (string, required) — The metadata key to extract
- `expression` (string, default `''`) — Optional expression to evaluate on the header value

**Behavior:**
- Non-nullable parameter: throws exception if header is missing
- Nullable parameter with default `null`: returns `null` if header is missing

## `#[Headers]`

Source: `Ecotone\Messaging\Attribute\Parameter\Headers`

Injects all message metadata as an associative array into a handler parameter.

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Headers
{
}
```

No constructor parameters.

## `#[AddHeader]`

Source: `Ecotone\Messaging\Attribute\Endpoint\AddHeader`

Declaratively adds a header to the message metadata. Applied on handler methods or classes.

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AddHeader
{
    public function __construct(string $name, mixed $value = null, string|null $expression = null)
}
```

**Parameters:**
- `name` (string, required) — The header key to add
- `value` (mixed, default `null`) — Static value for the header
- `expression` (string|null, default `null`) — Expression to compute the value dynamically

Either `$value` or `$expression` must be provided, not both.

**Expression context:** Expressions can access `payload` and `headers`. Example: `expression: 'headers["token"]'`

## `#[RemoveHeader]`

Source: `Ecotone\Messaging\Attribute\Endpoint\RemoveHeader`

Declaratively removes a header from the message metadata.

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RemoveHeader
{
    public function __construct(string $name)
}
```

**Parameters:**
- `name` (string, required) — The header key to remove

## `#[PropagateHeaders]`

Source: `Ecotone\Messaging\Attribute\PropagateHeaders`

Controls whether userland headers propagate from the current message to downstream messages. Applied on gateway methods.

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class PropagateHeaders
{
    public function __construct(bool $propagate)
}
```

**Parameters:**
- `propagate` (bool, required) — `false` to disable automatic header propagation

## Framework Headers Constants

Source: `Ecotone\Messaging\MessageHeaders`

| Constant | Value | Description |
|----------|-------|-------------|
| `MessageHeaders::MESSAGE_ID` | `'id'` | Unique message identifier |
| `MessageHeaders::MESSAGE_CORRELATION_ID` | `'correlationId'` | Correlates related messages |
| `MessageHeaders::PARENT_MESSAGE_ID` | `'parentId'` | Points to parent message |
| `MessageHeaders::TIMESTAMP` | `'timestamp'` | Message creation time |
| `MessageHeaders::CONTENT_TYPE` | `'contentType'` | Media type |
| `MessageHeaders::REVISION` | `'revision'` | Event revision number |
| `MessageHeaders::DELIVERY_DELAY` | `'deliveryDelay'` | Delay in milliseconds |
| `MessageHeaders::TIME_TO_LIVE` | `'timeToLive'` | TTL in milliseconds |
| `MessageHeaders::PRIORITY` | `'priority'` | Message priority |
| `MessageHeaders::EVENT_AGGREGATE_TYPE` | `'_aggregate_type'` | Aggregate class |
| `MessageHeaders::EVENT_AGGREGATE_ID` | `'_aggregate_id'` | Aggregate identifier |
| `MessageHeaders::EVENT_AGGREGATE_VERSION` | `'_aggregate_version'` | Aggregate version |

## Recorded Headers API

Available on `EcotoneLite` test instance via `getRecordedEventHeaders()` and `getRecordedCommandHeaders()`.

Each entry provides:

| Method | Returns | Description |
|--------|---------|-------------|
| `get(string $name)` | `mixed` | Get specific header value |
| `getMessageId()` | `string` | Get message ID |
| `getCorrelationId()` | `string` | Get correlation ID |
| `getParentId()` | `string` | Get parent message ID |
| `containsKey(string $name)` | `bool` | Check if header exists |
| `headers()` | `array` | Get all headers as array |
