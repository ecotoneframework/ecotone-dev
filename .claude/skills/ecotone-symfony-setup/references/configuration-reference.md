# Symfony Configuration Reference

## YAML Configuration (config/packages/ecotone.yaml)

```yaml
ecotone:
    # Service name for distributed architecture
    serviceName: 'my_service'

    # Auto-load classes from src/ directory (default: true)
    loadSrcNamespaces: true

    # Additional namespaces to scan
    namespaces:
        - 'App\CustomNamespace'

    # Fail fast in dev (validates configuration on boot)
    failFast: true

    # Default serialization format for async messages
    defaultSerializationMediaType: 'application/json'

    # Default error channel for async consumers
    defaultErrorChannel: 'errorChannel'

    # Memory limit for consumers (MB)
    defaultMemoryLimit: 256

    # Connection retry on failure
    defaultConnectionExceptionRetry:
        initialDelay: 100
        maxAttempts: 3
        multiplier: 2

    # Skip specific module packages
    skippedModulePackageNames: []

    # Enterprise licence key
    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'
```

## All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `serviceName` | `null` | Service identifier for distributed messaging |
| `failFast` | `false` | Validates config at boot (auto-enabled in dev) |
| `loadSrcNamespaces` | `true` | Auto-scan `src/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `defaultSerializationMediaType` | `null` | Media type for async serialization |
| `defaultErrorChannel` | `null` | Error channel name |
| `defaultMemoryLimit` | `null` | Consumer memory limit (MB) |
| `defaultConnectionExceptionRetry` | `null` | Retry config for connection failures |
| `skippedModulePackageNames` | `[]` | Module packages to skip |
| `licenceKey` | `null` | Enterprise licence key |
| `test` | `false` | Enable test mode |

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
