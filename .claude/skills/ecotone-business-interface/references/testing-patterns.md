# Business Interface Testing Patterns

## Testing BusinessMethod Gateways

Use `$ecotone->getGateway(InterfaceClass::class)` to obtain auto-generated implementations:

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [NotificationHandler::class],
    [new NotificationHandler()],
);

/** @var NotificationGateway $gateway */
$gateway = $ecotone->getGateway(NotificationGateway::class);
$gateway->send('Hello', 'user@example.com');
```

## Testing DBAL Interfaces

For DBAL interfaces, register the connection under `DbalConnectionReference::DEFAULT` and provide converters as
services:

```php
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;

$ecotone = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [ProductRepository::class, ProductConverter::class],
    containerOrAvailableServices: [
        DbalConnectionReference::DEFAULT => $connectionFactory,
        new ProductConverter(),
    ],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withModulePackages([ModulePackageList::DBAL_PACKAGE]),
);

/** @var ProductRepository $repository */
$repository = $ecotone->getGateway(ProductRepository::class);
$repository->insert('p-1', 'Widget', 100, 'tools');

$result = $repository->findById('p-1');
$this->assertEquals('Widget', $result['name']);
```
