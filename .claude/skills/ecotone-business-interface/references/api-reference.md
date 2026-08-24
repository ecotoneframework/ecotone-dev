# Business Interface API Reference

## DbalQuery Attribute

Source: `Ecotone\Dbal\Api\Attribute\DbalQuery`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalQuery
{
    public function __construct(
        public readonly string $sql = '',
        public readonly string $fetchMode = FetchMode::ASSOCIATIVE,
        public readonly string $connectionReferenceName = DbalConnection::class,
    )
}
```

## DbalWrite Attribute

Source: `Ecotone\Dbal\Api\Attribute\DbalWrite`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalWrite
{
    public function __construct(
        public readonly string $sql = '',
        public readonly string $connectionReferenceName = DbalConnection::class,
    )
}
```

## DbalParameter Attribute

Source: `Ecotone\Dbal\Api\Attribute\DbalParameter`

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

| Mode | Returns |
|------|---------|
| `FetchMode::ASSOCIATIVE` | Array of associative arrays |
| `FetchMode::FIRST_COLUMN` | Array of first column values |
| `FetchMode::FIRST_ROW` | Single associative array (first row) |
| `FetchMode::FIRST_COLUMN_OF_FIRST_ROW` | Single scalar value |
| `FetchMode::COLUMN_OF_FIRST_ROW` | Named column from first row |

## BusinessMethod / MessageGateway Attribute

Source: `Ecotone\Api\Attribute\BusinessMethod`

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
