# Licence Header Formats

Every PHP file in the Ecotone codebase must have a licence comment. The comment goes directly inside the class/interface/trait docblock or as a standalone comment after the namespace declaration.

## Apache-2.0 Licence (Open Source)

Used for all open-source packages. The format is a single-line comment placed as a docblock:

```php
/**
 * licence Apache-2.0
 */
class MyClass
{
}
```

```php
/**
 * licence Apache-2.0
 */
interface MyInterface
{
}
```

### Real examples from codebase

From `Ecotone\Messaging\Message`:
```php
/**
 * licence Apache-2.0
 */
interface Message
{
    public function getHeaders(): MessageHeaders;
    public function getPayload(): mixed;
}
```

From `Ecotone\Messaging\Config\ModulePackageList`:
```php
/**
 * licence Apache-2.0
 */
final class ModulePackageList
{
```

## Enterprise Licence

Used for enterprise/commercial features. Same format with different text:

```php
/**
 * licence Enterprise
 */
class MyEnterpriseFeature
{
}
```

### Real examples from codebase

From `Ecotone\Projecting\PartitionProvider`:
```php
/**
 * licence Enterprise
 */
```

## Rules

1. Every PHP file MUST have a licence comment
2. The licence docblock is placed directly above the class/interface/trait declaration
3. Use `Apache-2.0` for open-source code, `Enterprise` for commercial features
4. Enterprise-licenced files are typically in the Projecting namespace and related enterprise features
5. When in doubt, use `Apache-2.0` — the maintainer will request changes if needed
6. Test files also need licence headers
