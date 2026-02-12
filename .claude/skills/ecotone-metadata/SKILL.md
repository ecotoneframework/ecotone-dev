---
name: ecotone-metadata
description: >-
  Implements message metadata (headers) in Ecotone: #[Header] and #[Headers]
  for reading, #[AddHeader]/#[RemoveHeader] for enrichment, changeHeaders in
  interceptors, automatic propagation from commands to events. Use when
  passing custom headers, reading message metadata, enriching headers,
  propagating metadata across handlers, or testing metadata with EcotoneLite.
---

# Ecotone Message Metadata

## Overview

Every message in Ecotone carries metadata (headers) alongside its payload. Metadata includes framework headers (id, correlationId, timestamp) and custom userland headers (userId, tenant, token, etc.). Userland headers automatically propagate from commands to events.

## Passing Metadata When Sending Messages

All bus interfaces accept a `$metadata` array:

```php
$commandBus->send(new PlaceOrder('1'), metadata: ['userId' => '123']);
$eventBus->publish(new OrderWasPlaced('1'), metadata: ['source' => 'api']);
$queryBus->send(new GetOrder('1'), metadata: ['tenant' => 'acme']);
```

## Accessing Metadata in Handlers

### Single Header with `#[Header]`

```php
use Ecotone\Messaging\Attribute\Parameter\Header;

#[EventHandler]
public function audit(
    OrderWasPlaced $event,
    #[Header('userId')] string $userId,
    #[Header('tenant')] ?string $tenant = null  // nullable = optional
): void {
    // Non-nullable throws if missing; nullable returns null if missing
}
```

### All Headers with `#[Headers]`

```php
use Ecotone\Messaging\Attribute\Parameter\Headers;

#[CommandHandler('logCommand')]
public function log(#[Headers] array $headers): void
{
    $userId = $headers['userId'];
}
```

### Convention-Based (No Attribute)

When a handler has two parameters -- first is payload, second is `array` -- the second is automatically resolved as all headers:

```php
#[CommandHandler('placeOrder')]
public function handle($command, array $headers, EventBus $eventBus): void
{
    $userId = $headers['userId'];
}
```

## Enriching Metadata Declaratively

```php
use Ecotone\Messaging\Attribute\Endpoint\AddHeader;
use Ecotone\Messaging\Attribute\Endpoint\RemoveHeader;

#[AddHeader('token', '123')]
#[RemoveHeader('sensitiveData')]
#[CommandHandler('process')]
public function process(): void { }
```

## Modifying Metadata with Interceptors

Use `changeHeaders: true` on `#[Before]`, `#[After]`, or `#[Presend]`. The interceptor must return an array that gets merged into existing headers.

```php
#[Before(changeHeaders: true, pointcut: CommandHandler::class)]
public function addProcessedAt(#[Headers] array $headers): array
{
    return array_merge($headers, ['processedAt' => time()]);
}
```

## Automatic Metadata Propagation

Ecotone automatically propagates userland headers from commands to events:

```
Command (userId=123) -> CommandHandler -> publishes Event -> EventHandler receives (userId=123)
```

- All custom/userland headers propagate automatically
- `correlationId` is always preserved
- `parentId` is set to the command's `messageId`
- Framework headers do NOT propagate
- Disable with `#[PropagateHeaders(false)]` on gateway methods

## Key Rules

- Use `#[Header('name')]` for single header access, `#[Headers]` for all headers
- Convention: second `array` parameter is auto-resolved as headers (no attribute needed)
- `changeHeaders: true` only on `#[Before]`, `#[After]`, `#[Presend]` -- NOT `#[Around]`
- Interceptors with `changeHeaders: true` must return an array
- Userland headers propagate automatically from commands to events
- Framework headers do NOT propagate
- Use `getRecordedEventHeaders()` / `getRecordedCommandHeaders()` to verify metadata in tests

## Additional resources

- [API Reference](references/api-reference.md) — Attribute constructor signatures and parameter details for `#[Header]`, `#[Headers]`, `#[AddHeader]`, `#[RemoveHeader]`, `#[PropagateHeaders]`, and the framework headers constants table (`MessageHeaders`). Load when you need exact parameter names, types, or constant values.
- [Usage Examples](references/usage-examples.md) — Complete class implementations for all metadata patterns: handler header access, convention-based headers, declarative enrichment, Before/After/Presend interceptors with `changeHeaders`, custom attribute-based enrichment (`AddMetadata`), metadata propagation flow, event-sourced aggregate metadata, and `identifierMetadataMapping`. Load when you need full, copy-paste-ready class definitions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test methods for metadata: sending metadata in tests, verifying event/command headers, testing propagation, correlation/parent ID verification, Before interceptor header enrichment, AddHeader/RemoveHeader with async channels, async metadata propagation, event-sourced aggregate metadata, and disabled propagation. Load when writing tests for metadata flows.
