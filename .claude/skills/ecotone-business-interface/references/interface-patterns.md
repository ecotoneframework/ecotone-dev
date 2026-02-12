# Business Interface Patterns Reference

## DbalQueryBusinessMethod Attribute

Source: `Ecotone\Dbal\Attribute\DbalQueryBusinessMethod`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalQueryBusinessMethod
{
    public function __construct(
        public readonly string $sql = '',
        public readonly string $fetchMode = FetchMode::ASSOCIATIVE,
        public readonly string $connectionReferenceName = DbalConnection::class,
    )
}
```

## DbalWriteBusinessMethod Attribute

Source: `Ecotone\Dbal\Attribute\DbalWriteBusinessMethod`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalWriteBusinessMethod
{
    public function __construct(
        public readonly string $sql = '',
        public readonly string $connectionReferenceName = DbalConnection::class,
    )
}
```

## DbalParameter Attribute

Source: `Ecotone\Dbal\Attribute\DbalParameter`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class DbalParameter
{
    public function __construct(
        public readonly string $name = '',
        public readonly ?string $type = null,
        public readonly string $expression = '',
    )
}
```

## FetchMode Constants

Source: `Ecotone\Dbal\DbaBusinessMethod\FetchMode`

```php
class FetchMode
{
    public const ASSOCIATIVE = 'associative';
    public const FIRST_COLUMN = 'first_column';
    public const FIRST_ROW = 'first_row';
    public const FIRST_COLUMN_OF_FIRST_ROW = 'first_column_of_first_row';
    public const COLUMN_OF_FIRST_ROW = 'column_of_first_row';
}
```

## DBAL Query Examples

### Basic Queries

```php
use Ecotone\Dbal\Attribute\DbalQueryBusinessMethod;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

interface ProductRepository
{
    // Returns array of associative arrays
    #[DbalQueryBusinessMethod('SELECT * FROM products')]
    public function findAll(): array;

    // Returns single row or null
    #[DbalQueryBusinessMethod(
        'SELECT * FROM products WHERE id = :productId',
        fetchMode: FetchMode::FIRST_ROW
    )]
    public function findById(string $productId): ?array;

    // Returns scalar value
    #[DbalQueryBusinessMethod(
        'SELECT COUNT(*) FROM products WHERE category = :category',
        fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW
    )]
    public function countByCategory(string $category): int;

    // Returns array of single column values
    #[DbalQueryBusinessMethod(
        'SELECT name FROM products WHERE active = :active',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function getActiveProductNames(bool $active = true): array;
}
```

### Write Operations

```php
use Ecotone\Dbal\Attribute\DbalWriteBusinessMethod;

interface ProductWriter
{
    #[DbalWriteBusinessMethod(
        'INSERT INTO products (id, name, price, category) VALUES (:id, :name, :price, :category)'
    )]
    public function insert(string $id, string $name, int $price, string $category): void;

    #[DbalWriteBusinessMethod(
        'UPDATE products SET price = :price WHERE id = :id'
    )]
    public function updatePrice(string $id, int $price): void;

    #[DbalWriteBusinessMethod(
        'DELETE FROM products WHERE id = :id'
    )]
    public function delete(string $id): void;
}
```

### Parameter Type Conversion

```php
use Ecotone\Dbal\Attribute\DbalParameter;

interface AdvancedQueries
{
    #[DbalQueryBusinessMethod('SELECT * FROM events WHERE tags @> :tags')]
    public function findByTags(
        #[DbalParameter(type: 'json')] array $tags
    ): array;

    #[DbalQueryBusinessMethod('SELECT * FROM orders WHERE created_at > :since')]
    public function findRecent(
        #[DbalParameter(type: 'datetime')] \DateTimeInterface $since
    ): array;

    #[DbalQueryBusinessMethod('SELECT * FROM items WHERE id = ANY(:ids)')]
    public function findByIds(
        #[DbalParameter(type: 'json')] array $ids
    ): array;
}
```

### Expression-Based Parameters

```php
interface OrderQueries
{
    #[DbalQueryBusinessMethod('SELECT * FROM orders WHERE user_id = :userId')]
    public function findForUser(
        #[DbalParameter(expression: "headers['userId']")] string $userId
    ): array;
}
```

## Converter Examples

Source: `Ecotone\Messaging\Attribute\Converter`

```php
use Ecotone\Messaging\Attribute\Converter;

class ProductConverter
{
    #[Converter]
    public function fromArray(array $data): ProductDTO
    {
        return new ProductDTO(
            id: $data['id'],
            name: $data['name'],
            price: $data['price'],
        );
    }

    #[Converter]
    public function toArray(ProductDTO $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
        ];
    }
}
```

### JSON Converter

```php
class JsonConverter
{
    #[Converter]
    public function fromJson(string $json): OrderDTO
    {
        $data = json_decode($json, true);
        return new OrderDTO($data['orderId'], $data['product']);
    }

    #[Converter]
    public function toJson(OrderDTO $order): string
    {
        return json_encode([
            'orderId' => $order->orderId,
            'product' => $order->product,
        ]);
    }
}
```

## BusinessMethod Attribute

Source: `Ecotone\Messaging\Attribute\BusinessMethod`

`BusinessMethod` extends `MessageGateway`. Ecotone generates an implementation that sends messages through the messaging system.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class BusinessMethod extends MessageGateway
{
}

class MessageGateway
{
    public function __construct(
        string $requestChannel,
        string $errorChannel = '',
        int $replyTimeoutInMilliseconds = 0,
        array $requiredInterceptorNames = [],
        ?string $replyContentType = null
    )
}
```

### Basic: BusinessMethod → ServiceActivator

```php
use Ecotone\Messaging\Attribute\BusinessMethod;

interface CacheService
{
    #[BusinessMethod('cache.set')]
    public function set(CachedItem $item): void;

    #[BusinessMethod('cache.get')]
    public function get(string $key): ?string;
}

use Ecotone\Messaging\Attribute\ServiceActivator;

class InMemoryCache
{
    private array $items;

    #[ServiceActivator('cache.set')]
    public function set(CachedItem $item): void
    {
        $this->items[$item->key] = $item->value;
    }

    #[ServiceActivator('cache.get')]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}
```

### BusinessMethod → CommandHandler on Aggregate

```php
use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Modelling\Attribute\Identifier;

interface ProductService
{
    #[BusinessMethod('product.register')]
    public function registerProduct(RegisterProduct $command): void;

    #[BusinessMethod('product.changePrice')]
    public function changePrice(ChangePrice $command): void;

    #[BusinessMethod('product.getPrice')]
    public function getPrice(#[Identifier] string $productId): float;
}

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

### BusinessMethod with Headers and Routing

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

use Ecotone\Messaging\Attribute\Router;
use Ecotone\Messaging\Attribute\Parameter\Header;

class CachingRouter
{
    #[Router('cache.set')]
    public function routeSet(#[Header('cache.type')] CacheType $type): string
    {
        return match ($type) {
            CacheType::FILE_SYSTEM => 'cache.set.file_system',
            CacheType::IN_MEMORY => 'cache.set.in_memory',
        };
    }
}
```

### Injecting BusinessMethod into CommandHandlers

BusinessMethod interfaces can be injected as parameters into handler methods. Ecotone resolves the auto-generated proxy and passes it in.

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

use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Messaging\Attribute\Parameter\Reference;

#[EventSourcingAggregate]
class Basket
{
    #[Identifier]
    private UuidInterface $userId;
    private array $productIds;

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

    #[CommandHandler]
    public function add(
        AddProductToBasket $command,
        ProductService $productService
    ): array {
        if (in_array($command->productId, $this->productIds)) {
            return [];
        }

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

**Key patterns for injection:**
- First parameter after command is matched by type — Ecotone injects the BusinessMethod proxy automatically
- Use `#[Reference]` for explicit service container injection (when not first service parameter)
- Use `#[Identifier]` on BusinessMethod parameters to target specific aggregate instances

## MediaType Constants

Source: `Ecotone\Messaging\Conversion\MediaType`

```php
MediaType::APPLICATION_JSON             // 'application/json'
MediaType::APPLICATION_XML              // 'application/xml'
MediaType::APPLICATION_X_PHP            // 'application/x-php'
MediaType::APPLICATION_X_PHP_ARRAY      // 'application/x-php;type=array'
MediaType::APPLICATION_X_PHP_SERIALIZED // 'application/x-php-serialized'
MediaType::TEXT_PLAIN                   // 'text/plain'
MediaType::APPLICATION_OCTET_STREAM     // 'application/octet-stream'
```

## Custom Connection Reference

```php
interface SecondaryDbQueries
{
    #[DbalQueryBusinessMethod(
        'SELECT * FROM legacy_orders',
        connectionReferenceName: 'secondary_connection'
    )]
    public function findLegacyOrders(): array;
}
```
