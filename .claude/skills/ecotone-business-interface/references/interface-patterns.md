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

## BusinessMethod Examples

Source: `Ecotone\Messaging\Attribute\BusinessMethod`

```php
use Ecotone\Messaging\Attribute\BusinessMethod;

interface NotificationGateway
{
    #[BusinessMethod('notification.send')]
    public function send(string $message, string $recipient): void;
}

// Handler that processes the business method call
class NotificationHandler
{
    #[ServiceActivator(inputChannelName: 'notification.send')]
    public function handle(string $message): void
    {
        // Process notification
    }
}
```

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
