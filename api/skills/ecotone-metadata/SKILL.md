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

## Applies when

- Passing context that is not part of the business payload (user id, tenant, correlation data, tokens) with a command, event or query
- Reading one header or all headers inside a handler
- Adding or removing headers declaratively or from an interceptor
- Relying on, or switching off, header propagation from a command to the events it causes
- Asserting headers in a test with `EcotoneLite`

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
use Ecotone\Api\Attribute\Header;

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
use Ecotone\Api\Attribute\Headers;

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
use Ecotone\Api\Attribute\AddHeader;
use Ecotone\Api\Attribute\RemoveHeader;

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

## Rules

### 1. Context travels as metadata, not as payload fields
Every bus method takes a `metadata:` array (`send()`, `sendWithRouting()`, `publish()`, `publishWithRouting()`); keep
the command or event a pure business message.
- **Expected:** values such as the acting user or tenant are passed as `metadata: ['userId' => ...]` on the bus call, and no `userId`/`tenantId` property is added to the command or event class for that purpose.
- **Verified by:** `eval:request-context-headers`

### 2. Read one header with `#[Header]`, and type it nullable when it may be missing
`#[Header('userId')] string $userId` fails when the header is absent; `?string` (or a default value) makes it
optional. `#[Headers] array $headers` (or an untyped second `array` parameter) receives all headers.
- **Expected:** a handler reads a header with `#[Header('<name>')]`; a header that is not always present is declared `?type` or with a default, and a required one is non-nullable.
- **Verified by:** `eval:request-context-headers`

### 3. Userland headers propagate from a command to the events it publishes
Headers sent with a command reach the events its handler publishes through the `EventBus` or an aggregate, on
synchronous and asynchronous handlers alike; `correlationId` is kept and `parentId` becomes the command's message id.
Framework headers do not propagate.
- **Expected:** an event handler reads the command's header directly with `#[Header]`, and the command handler does not copy the header into the event payload or re-pass it by hand.
- **Verified by:** `eval:request-context-headers`

### 4. Interceptors change headers with `changeHeaders: true` and return an array
On `#[Before]`, `#[After]` or `#[Presend]`, `changeHeaders: true` merges the returned array into the headers;
`#[Around]` cannot change headers.
- **Expected:** a header computed for every command is added by a `#[Before]`/`#[Presend]` interceptor with `changeHeaders: true` returning an array, not by an `#[Around]` interceptor or by editing every call site.
- **Verified by:** `eval:request-context-headers`

### 5. Tests assert headers through the recorded messages
`popRecordedEventHeaders()` and `popRecordedCommandHeaders()` return `MessageHeaders` objects, read with `->get('<name>')`.
- **Expected:** a flow test sends the command with `metadata:` and asserts the header on the published event with `$ecotone->popRecordedEventHeaders()[0]->get('<name>')` (or by observing it in a handler), not by reading internals.
- **Verified by:** `eval:request-context-headers`

## Additional resources

- [API Reference](references/api-reference.md) — Attribute constructor signatures and parameter details for `#[Header]`, `#[Headers]`, `#[AddHeader]`, `#[RemoveHeader]`, `#[PropagateHeaders]`, and the framework headers constants table (`MessageHeaders`). Load when you need exact parameter names, types, or constant values.
- [Usage Examples](references/usage-examples.md) — Complete class implementations for all metadata patterns: handler header access, convention-based headers, declarative enrichment, Before/After/Presend interceptors with `changeHeaders`, custom attribute-based enrichment (`AddMetadata`), metadata propagation flow, event-sourced aggregate metadata, and `identifierMetadataMapping`. Load when you need full, copy-paste-ready class definitions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test methods for metadata: sending metadata in tests, verifying event/command headers, testing propagation, correlation/parent ID verification, Before interceptor header enrichment, AddHeader/RemoveHeader with async channels, async metadata propagation, event-sourced aggregate metadata, and disabled propagation. Load when writing tests for metadata flows.
