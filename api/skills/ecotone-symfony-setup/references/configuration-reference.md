# Symfony Configuration Reference

## YAML Configuration (config/packages/ecotone.yaml)

```yaml
ecotone:
    # Auto-load classes from src/ directory (default: true)
    loadSrcNamespaces: true

    # Additional namespaces to scan
    namespaces:
        - 'App\CustomNamespace'

    # Module packages to load besides Core (e.g. dbal, amqp); omit the key
    # entirely to load every installed package, [] loads Core alone
    enabledModulePackageNames: [dbal, amqp]

    # Enterprise licence key
    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'

    # Load the test support module
    test: false
```

## All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `loadSrcNamespaces` | `true` | Auto-scan `src/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `enabledModulePackageNames` | every installed package | Explicit list of module packages to load, besides Core; the key must be present to restrict loading at all |
| `licenceKey` | `null` | Enterprise licence key |
| `test` | `false` | Enable test mode |

The service name, default serialization media type, default error channel, consumer memory limit, connection retry
template and headers in log context are not bundle keys. They are set on `ServiceConfiguration`, returned from exactly
one `#[ServiceContext]` method:

```php
#[ServiceContext]
public function serviceConfiguration(): ServiceConfiguration
{
    return ServiceConfiguration::createWithDefaults()
        ->withServiceName('my_service')
        ->withDefaultSerializationMediaType('application/json')
        ->withDefaultErrorChannel('errorChannel')
        ->withConsumerMemoryLimit(256)
        ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackOff(100, 2)->maxRetries(3))
        ->withHeadersInLogContext(['userId']);
}
```

The container cache is owned by the Symfony kernel: `cache:clear` rebuilds it.

Any other key is refused at bootstrap with a `ConfigurationException` naming the keys Ecotone reads. The 1.x
`skippedModulePackageNames` key and the earlier 2.0 `modulePackages` key are refused with their replacement:
`skippedModulePackageNames` listed packages to skip, `enabledModulePackageNames` lists packages to load.

## SymfonyConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultManagerRegistry(connectionName, managerRegistry)` | Default connection via Doctrine ManagerRegistry |
| `createForManagerRegistry(connectionName, managerRegistry, referenceName)` | Named connection via ManagerRegistry |
| `defaultConnection(connectionName)` | Default connection without ManagerRegistry |
| `createForConnection(connectionName, referenceName)` | Named connection without ManagerRegistry |

## Doctrine DBAL Configuration (config/packages/doctrine.yaml)

```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
```
