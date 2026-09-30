# Business Interface API Reference

## DbalQuery Attribute

Source: `Ecotone\Api\Dbal\Attribute\DbalQuery`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalQuery
{
    public function __construct(
        private string  $sql,
        private int     $fetchMode = FetchMode::ASSOCIATIVE,
        private ?string $replyContentType = null,
        private string  $connectionReferenceName = DbalConnectionReference::DEFAULT
    )
}
```

## DbalWrite Attribute

Source: `Ecotone\Api\Dbal\Attribute\DbalWrite`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class DbalWrite
{
    public function __construct(
        private string $sql,
        private string $connectionReferenceName = DbalConnectionReference::DEFAULT
    )
}
```

## DbalParameter Attribute

Source: `Ecotone\Api\Dbal\Attribute\DbalParameter`. Can target a parameter, or a method/class (paired with a
`name` to bind an expression-derived value that has no matching method parameter).

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class DbalParameter
{
    public function __construct(
        private ?string $name = null,
        private int|ArrayParameterType|ParameterType|null $type = null,
        private string|Closure|null $expression = null,
        private ?string $convertToMediaType = null,
        private bool $ignored = false
    )
}
```

- `type` -- a `Doctrine\DBAL\ParameterType` or `Doctrine\DBAL\ArrayParameterType` case, for how the value is bound
  (e.g. `ArrayParameterType::STRING` for `WHERE id = ANY(:ids)`). Not a string.
- `convertToMediaType` -- converts the PHP value to this media type before binding (e.g.
  `MediaType::APPLICATION_JSON` to store an array as a JSON string).
- `expression` -- a SpEL expression, or closure, evaluated to produce the bound value.
- A `\DateTimeInterface` parameter needs neither `type` nor `convertToMediaType` -- it converts automatically.

## FetchMode Constants

Source: `Ecotone\Dbal\DbaBusinessMethod\FetchMode`

```php
final class FetchMode
{
    public const ASSOCIATIVE = 0;
    public const FIRST_COLUMN = 1;
    public const FIRST_ROW = 2;
    public const FIRST_COLUMN_OF_FIRST_ROW = 3;
    public const ITERATE = 4;
}
```

| Mode | Returns |
|------|---------|
| `FetchMode::ASSOCIATIVE` | Array of associative arrays |
| `FetchMode::FIRST_COLUMN` | Array of first column values |
| `FetchMode::FIRST_ROW` | Single associative array (first row); `null` if the method's return type is nullable and there is no row |
| `FetchMode::FIRST_COLUMN_OF_FIRST_ROW` | Single scalar value |
| `FetchMode::ITERATE` | Generator yielding one associative array per row |

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
        int $replyTimeoutInMilliseconds = GatewayProxyBuilder::DEFAULT_REPLY_MILLISECONDS_TIMEOUT, // -1: wait indefinitely
        array $requiredInterceptorNames = [],
        ?string $replyContentType = null
    )
}
```

## MediaType Constants

Source: `Ecotone\Api\ExtensionObject\MediaType`

```php
MediaType::APPLICATION_JSON             // 'application/json'
MediaType::APPLICATION_XML              // 'application/xml'
MediaType::APPLICATION_X_PHP            // 'application/x-php'
MediaType::APPLICATION_X_PHP_ARRAY      // 'application/x-php;type=array'
MediaType::APPLICATION_X_PHP_SERIALIZED // 'application/x-php-serialized'
MediaType::TEXT_PLAIN                   // 'text/plain'
MediaType::APPLICATION_OCTET_STREAM     // 'application/octet-stream'
```
