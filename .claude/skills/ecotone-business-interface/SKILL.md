---
name: ecotone-business-interface
description: >-
  Creates Ecotone business interfaces: DBAL query interfaces, repository
  abstractions, expression language usage, and media type converters.
  Use when creating database queries, custom repositories, data
  converters, or business method interfaces.
---

# Ecotone Business Interfaces

## 1. DBAL Query Interfaces

Create database query methods as interface declarations — Ecotone generates the implementation.

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

    #[DbalWriteBusinessMethod('UPDATE orders SET status = :status WHERE order_id = :orderId')]
    public function updateStatus(string $orderId, string $status): void;
}
```

### FetchMode Options

Source: `Ecotone\Dbal\DbaBusinessMethod\FetchMode`

| Mode | Returns |
|------|---------|
| `FetchMode::ASSOCIATIVE` | Array of associative arrays |
| `FetchMode::FIRST_COLUMN` | Array of first column values |
| `FetchMode::FIRST_ROW` | Single associative array (first row) |
| `FetchMode::FIRST_COLUMN_OF_FIRST_ROW` | Single scalar value |
| `FetchMode::COLUMN_OF_FIRST_ROW` | Named column from first row |

### DbalParameter Attribute

Source: `Ecotone\Dbal\Attribute\DbalParameter`

For parameter transformation:

```php
use Ecotone\Dbal\Attribute\DbalParameter;

interface ProductRepository
{
    #[DbalQueryBusinessMethod('SELECT * FROM products WHERE tags @> :tags')]
    public function findByTags(
        #[DbalParameter(type: 'json')] array $tags
    ): array;
}
```

## 2. Media Type Converters

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

### MediaType Class

Source: `Ecotone\Messaging\Conversion\MediaType`

```php
use Ecotone\Messaging\Conversion\MediaType;

MediaType::APPLICATION_JSON           // 'application/json'
MediaType::APPLICATION_XML            // 'application/xml'
MediaType::APPLICATION_X_PHP          // 'application/x-php'
MediaType::APPLICATION_X_PHP_ARRAY    // 'application/x-php;type=array'
MediaType::TEXT_PLAIN                 // 'text/plain'
```

## 3. Business Method Interfaces

Source: `Ecotone\Messaging\Attribute\BusinessMethod`

BusinessMethod is an interface-only attribute — Ecotone auto-generates the implementation that sends messages through the messaging system. The `requestChannel` parameter routes to the matching handler's routing key.

### Basic: BusinessMethod → ServiceActivator

```php
use Ecotone\Messaging\Attribute\BusinessMethod;

interface NotificationGateway
{
    #[BusinessMethod('notification.send')]
    public function send(string $message, string $recipient): void;
}

// Handler
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

### BusinessMethod → CommandHandler (Aggregate)

Use BusinessMethod to call CommandHandler on aggregates directly, bypassing CommandBus:

```php
use Ecotone\Messaging\Attribute\BusinessMethod;

interface ProductService
{
    #[BusinessMethod('product.register')]
    public function registerProduct(RegisterProduct $command): void;

    #[BusinessMethod('product.changePrice')]
    public function changePrice(ChangePrice $command): void;

    #[BusinessMethod('product.getPrice')]
    public function getPrice(#[Identifier] string $productId): float;
}

// Aggregate with matching routing keys
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[EventSourcingAggregate]
class Product
{
    #[Identifier]
    private string $productId;
    private float $price;

    #[CommandHandler('product.register')]
    public static function register(RegisterProduct $command): array
    {
        return [new ProductWasRegistered($command->productId, $command->price)];
    }

    #[CommandHandler('product.changePrice')]
    public function changePrice(ChangePrice $command): array
    {
        return [new PriceWasChanged($this->productId, $command->price)];
    }

    #[QueryHandler('product.getPrice')]
    public function getPrice(): float
    {
        return $this->price;
    }
}
```

### BusinessMethod with Headers

Pass metadata as message headers using `#[Header]`:

```php
use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Messaging\Attribute\Parameter\Header;

interface CacheService
{
    #[BusinessMethod('cache.set')]
    public function set(CachedItem $item, #[Header('cache.type')] CacheType $type): void;

    #[BusinessMethod('cache.get')]
    public function get(string $key, #[Header('cache.type')] CacheType $type): ?string;
}
```

### Injecting BusinessMethod into CommandHandlers

BusinessMethod interfaces can be injected as parameters into CommandHandlers for cross-aggregate communication:

```php
use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

interface ProductService
{
    #[BusinessMethod('product.getPrice')]
    public function getPrice(#[Identifier] UuidInterface $productId): int;
}

interface UserService
{
    #[BusinessMethod('user.isVerified')]
    public function isUserVerified(#[Identifier] UuidInterface $userId): bool;
}

// Aggregate that uses BusinessMethod interfaces as dependencies
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Messaging\Attribute\Parameter\Reference;

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

    #[CommandHandler('order.placeOrder')]
    public function placeOrder(#[Reference] UserService $userService): array
    {
        Assert::that($userService->isUserVerified($this->userId))->true(
            'User must be verified to place order'
        );

        return [new OrderWasPlaced($this->userId, $this->productIds)];
    }
}
```

**Key**: When the BusinessMethod interface is type-hinted as a parameter on a CommandHandler method, Ecotone injects the auto-generated proxy. Use `#[Reference]` when injecting via service container reference (e.g., for non-first parameters or explicit injection).

## 4. Expression Language

Ecotone attributes support expressions for dynamic behavior:

```php
use Ecotone\Modelling\Attribute\CommandHandler;

class OrderService
{
    // Route based on payload property
    #[CommandHandler(routingKey: "payload.type")]
    public function handle(array $payload): void { }
}
```

Available variables in expressions:
- `payload` — message payload
- `headers` — message headers

## 5. Repository Pattern

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

## 6. Testing Business Interfaces

```php
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;

public function test_dbal_query_interface(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        containerOrAvailableServices: [
            DbalConnectionFactory::class => $this->getConnectionFactory(),
            PersonNameDTOConverter::class => new PersonNameDTOConverter(),
        ],
        configuration: ServiceConfiguration::createWithDefaults()
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                ModulePackageList::DBAL_PACKAGE,
                ModulePackageList::JMS_CONVERTER_PACKAGE,
            ]))
            ->withNamespaces(['App\ReadModel']),
    );

    /** @var PersonService $writeGateway */
    $writeGateway = $ecotone->getGateway(PersonService::class);
    $writeGateway->insert(1, 'John');

    /** @var PersonQueryApi $queryGateway */
    $queryGateway = $ecotone->getGateway(PersonQueryApi::class);

    $this->assertEquals(
        [['person_id' => 1, 'name' => 'John']],
        $queryGateway->getNameList(1, 0)
    );
}

public function test_business_method_gateway(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [NotificationHandler::class],
        [new NotificationHandler()],
    );

    /** @var NotificationGateway $gateway */
    $gateway = $ecotone->getGateway(NotificationGateway::class);
    $gateway->send('Hello', 'user@example.com');

    // Assert on handler side effects
}
```

Key testing patterns:
- Use `$ecotone->getGateway(InterfaceClass::class)` to get the auto-generated implementation
- For DBAL interfaces, provide `DbalConnectionFactory` and converters as services
- Use `withNamespaces()` to specify where interfaces are located
- Business method gateways are tested by calling the interface method and asserting handler side effects

## Key Rules

- DBAL interfaces use method parameters as SQL bind parameters (`:paramName`)
- `#[Converter]` methods are auto-discovered — no manual registration needed
- Converters work bidirectionally if you define both directions
- FetchMode determines the shape of query results
- See `references/interface-patterns.md` for detailed examples
