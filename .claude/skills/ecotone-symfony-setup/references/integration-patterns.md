# Symfony Integration Patterns

## Doctrine ORM Integration -- Full Example

Enable Doctrine ORM repositories so aggregates can be stored as Doctrine entities:

```php
use Ecotone\Dbal\Configuration\DbalConfiguration;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbalConfig(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withDoctrineORMRepositories(true);
    }
}
```

Configure entity mappings in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
    orm:
        auto_generate_proxy_classes: '%kernel.debug%'
        entity_managers:
            default:
                connection: default
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
                        alias: App
```

### Doctrine Entity Aggregate (Full Example)

```php
use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[Aggregate]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    #[Identifier]
    private string $orderId;

    #[ORM\Column(type: 'boolean')]
    private bool $cancelled = false;

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        return $order;
    }

    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        $this->cancelled = true;
    }
}
```

## Database Connection (DBAL) -- Full Examples

### Default Connection via ManagerRegistry

```php
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('default');
    }
}
```

### Multiple Connections

```php
#[ServiceContext]
public function connections(): array
{
    return [
        SymfonyConnectionReference::defaultManagerRegistry('default'),
        SymfonyConnectionReference::createForManagerRegistry(
            'reporting',
            'doctrine',
            'reporting_connection'
        ),
    ];
}
```

## Symfony Messenger Channel -- Full Examples

Configure transports in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'doctrine://default?queue_name=async'
                options:
                    use_notify: false
            amqp_async:
                dsn: '%env(RABBITMQ_DSN)%'
```

Register as Ecotone channels via `#[ServiceContext]`:

```php
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): SymfonyMessengerMessageChannelBuilder
    {
        return SymfonyMessengerMessageChannelBuilder::create('async');
    }

    #[ServiceContext]
    public function amqpChannel(): SymfonyMessengerMessageChannelBuilder
    {
        return SymfonyMessengerMessageChannelBuilder::create('amqp_async');
    }
}
```

### Using DBAL Channels Directly

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}
```

## Multi-Tenant Configuration -- Full Example

```php
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenant(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => SymfonyConnectionReference::createForManagerRegistry('tenant_a_connection'),
                'tenant_b' => SymfonyConnectionReference::createForManagerRegistry('tenant_b_connection'),
            ],
        );
    }
}
```

With Doctrine ORM multi-tenant setup in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        default_connection: tenant_a_connection
        connections:
            tenant_a_connection:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
            tenant_b_connection:
                url: '%env(resolve:SECONDARY_DATABASE_DSN)%'
                charset: UTF8
    orm:
        entity_managers:
            tenant_a_connection:
                connection: tenant_a_connection
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
            tenant_b_connection:
                connection: tenant_b_connection
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
```
