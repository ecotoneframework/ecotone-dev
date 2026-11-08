---
name: ecotone-business-interface
description: >-
  Creates Ecotone business interfaces (gateways): DBAL query interfaces
  with #[DbalQuery] and #[DbalWrite], repository abstractions, expression language
  parameters, and media type converters. Use when creating database query
  interfaces, custom repository gateways, data converters, or abstract
  interface-based message sending with BusinessMethod.
---

# Ecotone Business Interfaces

## Overview

Business interfaces let you declare PHP interfaces that Ecotone auto-implements at runtime. They cover database queries (DBAL), type converters, messaging gateways (`BusinessMethod`), and repository abstractions. Use this skill when you need to create any of these interface-driven patterns.

## Applies when

- Writing a read model or query against your own tables as an interface with `#[DbalQuery]` / `#[DbalWrite]`
- Returning DTOs or value objects from a database query, or converting between arrays and objects with `#[Converter]`
- Declaring a messaging gateway interface with `#[BusinessMethod]` to send a message to a handler
- Reaching an aggregate through your own repository interface with `#[Repository]`

## 1. DBAL Query Interface

```php
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface OrderRepository
{
    #[DbalQuery('SELECT * FROM orders WHERE order_id = :orderId', fetchMode: FetchMode::FIRST_ROW)]
    public function findById(string $orderId): ?array;

    #[DbalQuery(
        'SELECT * FROM orders WHERE status = :status',
        fetchMode: FetchMode::ASSOCIATIVE
    )]
    public function findByStatus(string $status): array;

    #[DbalWrite('INSERT INTO orders (order_id, product, status) VALUES (:orderId, :product, :status)')]
    public function save(string $orderId, string $product, string $status): void;
}
```

## 2. Media Type Converter

```php
use Ecotone\Api\Attribute\Converter;

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
use Ecotone\Api\Attribute\BusinessMethod;

interface NotificationGateway
{
    #[BusinessMethod('notification.send')]
    public function send(string $message, #[Header('recipient')] string $recipient): void;
}

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;

class NotificationHandler
{
    #[InternalHandler('notification.send')]
    public function handle(string $message, #[Header('recipient')] string $recipient): void
    {
    }
}
```

The first parameter becomes the message payload, and a second `array` parameter becomes the message headers. Any
other parameter must be mapped with `#[Header('name')]` (or `#[Headers]`); an unmapped one is not sent.

## 4. BusinessMethod Injection into Handlers

BusinessMethod interfaces can be injected as parameters into CommandHandler methods for cross-aggregate communication:

```php
use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\Identifier;

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

`#[DbalParameter(expression: ...)]` computes the bound value. On a parameter, `payload` is that parameter's value; on
the method or the class, the method's parameters are available by name. `reference('serviceId')` reaches a service:

```php
use Ecotone\Api\Dbal\Attribute\DbalParameter;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;

interface PersonApi
{
    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :name)')]
    public function register(int $personId, #[DbalParameter(expression: 'payload.toLowerCase()')] PersonName $name): void;

    #[DbalQuery('SELECT person_id, name FROM persons LIMIT :limit OFFSET :offset')]
    #[DbalParameter('limit', expression: 'pagination.limit')]
    #[DbalParameter('offset', expression: 'pagination.offset')]
    public function list(Pagination $pagination): array;
}
```

## 6. Repository Pattern

Ecotone auto-generates repositories for aggregates. To reach one through an interface of your own, put
`#[Repository]` on each method -- Ecotone generates the implementation:

```php
use Ecotone\Api\Attribute\Repository;

interface CustomOrderRepository
{
    #[Repository]
    public function findOrder(string $orderId): ?Order;

    #[Repository]
    public function saveOrder(Order $order): void;
}
```

A class-level `#[Repository]` means something else: it marks a class you wrote as a custom repository
implementation (one implementing `Ecotone\Api\Modelling\StateStoredRepository` or
`Ecotone\Api\Modelling\EventSourcedRepository`). On an interface it is refused at bootstrap.

## Rules

### 1. Declare the interface; Ecotone writes the implementation
A `#[DbalQuery]`, `#[DbalWrite]`, `#[BusinessMethod]` or `#[Repository]` method lives on an interface. Inject the
interface where it is needed (or fetch it with `getGateway()` in a test); never write a class implementing it.
- **Expected:** the answer is a PHP `interface` whose methods carry the attributes, with no class that implements the
  interface and no hand-written SQL execution (`$connection->executeQuery(...)`) for those methods.
- **Verified by:** `eval:order-read-model`

### 2. Match the fetch mode to the return type
The default `FetchMode::ASSOCIATIVE` returns a list of rows. A single row needs `FetchMode::FIRST_ROW` (a nullable
return type gives `null` when nothing matches), a single value `FetchMode::FIRST_COLUMN_OF_FIRST_ROW`, one column of
every row `FetchMode::FIRST_COLUMN`, and a lazy row-by-row read `FetchMode::ITERATE`.
- **Expected:** a method returning one row or one DTO uses `fetchMode: FetchMode::FIRST_ROW`, a count or other single
  value uses `FetchMode::FIRST_COLUMN_OF_FIRST_ROW`, and a list keeps the default.
- **Verified by:** `eval:order-read-model`

### 3. Bind every SQL placeholder from a parameter of the same name
`:orderId` in the SQL is filled from the method parameter `$orderId`; a value with no parameter of its own comes from
`#[DbalParameter('name', expression: ...)]`. An array parameter used in `IN (:ids)` is bound as a list of strings
or integers from its values; `#[DbalParameter(type: ArrayParameterType::STRING)]` states the type explicitly.
- **Expected:** each `:placeholder` in the SQL matches a method parameter name or a `#[DbalParameter]` `name`; no
  SQL is built by string concatenation of method arguments.
- **Verified by:** `eval:order-read-model`

### 4. Return objects through a `#[Converter]`
To return a DTO (or `DTO[]` with an `@return` array docblock, or a nullable DTO) from a query, declare it as the return
type and provide a `#[Converter]` method from `array` to the DTO in a class Ecotone loads. Converters are found by
their attribute; there is nothing to register.
- **Expected:** the query method's return type is the DTO (or `array` with `@return Dto[]`), and a class with a
  `#[Converter]` method `fromArray(array $row): Dto` is provided instead of mapping rows by hand after the call.
- **Verified by:** `eval:order-read-model`

### 5. Map every gateway parameter beyond the payload with `#[Header]`
A `#[BusinessMethod]` sends its first parameter as the payload and a second `array` parameter as headers. Any further
value needs `#[Header('name')]`, and the handler reads it with the same `#[Header('name')]`.
- **Expected:** a `#[BusinessMethod]` with more than one non-array parameter marks the extra ones with
  `#[Header('...')]`, and the receiving handler declares matching `#[Header('...')]` parameters.
- **Verified by:** `eval:order-read-model`

Services a handler needs after its payload parameter are injected by their type; `#[Reference('id')]` names a
container service whose id is not its class, or marks a service that would otherwise be read as the payload.

## Additional resources

- [API reference](references/api-reference.md) -- Attribute constructor signatures and parameter lists for `DbalQuery`, `DbalWrite`, `DbalParameter`, `BusinessMethod`/`MessageGateway`, `FetchMode` constants, and `MediaType` constants. Load when you need exact constructor parameters, types, or defaults.

- [Usage examples](references/usage-examples.md) -- Complete, runnable code examples for all business interface patterns: advanced DBAL queries with parameter type conversion and expressions, write operations, JSON converters, BusinessMethod with headers and routing, cross-aggregate injection with `#[Reference]`, custom connection references. Load when you need full class implementations or advanced variations beyond the basic patterns above.

- [Testing patterns](references/testing-patterns.md) -- How to test business interfaces with `EcotoneLite::bootstrapFlowTesting()`, including gateway retrieval via `getGateway()`, DBAL interface testing setup, and converter testing patterns. Load when writing tests for business interfaces.
