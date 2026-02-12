---
name: ecotone-business-interface
description: >-
  Creates Ecotone business interfaces (gateways): DBAL query interfaces
  with #[DbalBusinessMethod], repository abstractions, expression language
  parameters, and media type converters. Use when creating database query
  interfaces, custom repository gateways, data converters, or abstract
  interface-based message sending with BusinessMethod.
---

# Ecotone Business Interfaces

## Overview

Business interfaces let you declare PHP interfaces that Ecotone auto-implements at runtime. They cover database queries (DBAL), type converters, messaging gateways (`BusinessMethod`), and repository abstractions. Use this skill when you need to create any of these interface-driven patterns.

## 1. DBAL Query Interface

```php
use Ecotone\Dbal\Attribute\DbalQueryBusinessMethod;
use Ecotone\Dbal\Attribute\DbalWriteBusinessMethod;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

interface OrderRepository
{
    #[DbalQueryBusinessMethod('SELECT * FROM orders WHERE order_id = :orderId')]
    public function findById(string $orderId): ?array;

    #[DbalQueryBusinessMethod(
        'SELECT * FROM orders WHERE status = :status',
        fetchMode: FetchMode::ASSOCIATIVE
    )]
    public function findByStatus(string $status): array;

    #[DbalWriteBusinessMethod('INSERT INTO orders (order_id, product, status) VALUES (:orderId, :product, :status)')]
    public function save(string $orderId, string $product, string $status): void;
}
```

## 2. Media Type Converter

```php
use Ecotone\Messaging\Attribute\Converter;

class OrderConverter
{
    #[Converter]
    public function fromArray(array $data): OrderDTO
    {
        return new OrderDTO(
            orderId: $data['order_id'],
            product: $data['product'],
            status: $data['status'],
        );
    }

    #[Converter]
    public function toArray(OrderDTO $order): array
    {
        return [
            'order_id' => $order->orderId,
            'product' => $order->product,
            'status' => $order->status,
        ];
    }
}
```

The framework auto-discovers converters and uses them for type conversion in message handling.

## 3. BusinessMethod Gateway

`BusinessMethod` is an interface-only attribute -- Ecotone auto-generates an implementation that sends messages through the messaging system. The `requestChannel` routes to the matching handler's routing key.

```php
use Ecotone\Messaging\Attribute\BusinessMethod;

interface NotificationGateway
{
    #[BusinessMethod('notification.send')]
    public function send(string $message, string $recipient): void;
}

use Ecotone\Messaging\Attribute\ServiceActivator;

class NotificationHandler
{
    #[ServiceActivator('notification.send')]
    public function handle(string $message): void
    {
        // Process notification
    }
}
```

## 4. BusinessMethod Injection into Handlers

BusinessMethod interfaces can be injected as parameters into CommandHandler methods for cross-aggregate communication:

```php
use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Modelling\Attribute\Identifier;

interface ProductService
{
    #[BusinessMethod('product.getPrice')]
    public function getPrice(#[Identifier] string $productId): int;
}

#[EventSourcingAggregate]
class Basket
{
    #[CommandHandler]
    public static function addToNewBasket(
        AddProductToBasket $command,
        ProductService $productService
    ): array {
        return [new ProductWasAddedToBasket(
            $command->userId,
            $command->productId,
            $productService->getPrice($command->productId)
        )];
    }
}
```

Use `#[Reference]` for explicit service container injection when it is not the first service parameter.

## 5. Expression Language

Ecotone attributes support expressions for dynamic behavior:

```php
use Ecotone\Modelling\Attribute\CommandHandler;

class OrderService
{
    #[CommandHandler(routingKey: "payload.type")]
    public function handle(array $payload): void { }
}
```

Available variables: `payload` (message payload), `headers` (message headers).

## 6. Repository Pattern

Ecotone auto-generates repositories for aggregates. For custom repositories:

```php
use Ecotone\Modelling\Attribute\Repository;

#[Repository]
interface CustomOrderRepository
{
    public function findOrder(string $orderId): ?Order;
    public function saveOrder(Order $order): void;
}
```

## Key Rules

- DBAL interfaces use method parameters as SQL bind parameters (`:paramName`)
- `#[Converter]` methods are auto-discovered -- no manual registration needed
- Converters work bidirectionally if you define both directions
- FetchMode determines the shape of query results
- When injecting BusinessMethod into handlers, first parameter after command is matched by type automatically; use `#[Reference]` for non-first service parameters

## Additional resources

- [API reference](references/api-reference.md) -- Attribute constructor signatures and parameter lists for `DbalQueryBusinessMethod`, `DbalWriteBusinessMethod`, `DbalParameter`, `BusinessMethod`/`MessageGateway`, `FetchMode` constants, and `MediaType` constants. Load when you need exact constructor parameters, types, or defaults.

- [Usage examples](references/usage-examples.md) -- Complete, runnable code examples for all business interface patterns: advanced DBAL queries with parameter type conversion and expressions, write operations, JSON converters, BusinessMethod with headers and routing, cross-aggregate injection with `#[Reference]`, custom connection references. Load when you need full class implementations or advanced variations beyond the basic patterns above.

- [Testing patterns](references/testing-patterns.md) -- How to test business interfaces with `EcotoneLite::bootstrapFlowTesting()`, including gateway retrieval via `getGateway()`, DBAL interface testing setup, and converter testing patterns. Load when writing tests for business interfaces.
