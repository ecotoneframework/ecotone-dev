# Laravel Configuration Reference

## Configuration File (config/ecotone.php)

```php
return [
    // Service name for distributed architecture
    'serviceName' => env('ECOTONE_SERVICE_NAME'),

    // Auto-load classes from app/ directory (default: true)
    'loadAppNamespaces' => true,

    // Additional namespaces to scan
    'namespaces' => [],

    // Cache configuration (auto-enabled in prod/production)
    'cacheConfiguration' => env('ECOTONE_CACHE', false),

    // Default serialization format for async messages
    'defaultSerializationMediaType' => env('ECOTONE_DEFAULT_SERIALIZATION_TYPE'),

    // Default error channel for async consumers
    'defaultErrorChannel' => env('ECOTONE_DEFAULT_ERROR_CHANNEL'),

    // Connection retry on failure
    'defaultConnectionExceptionRetry' => null,

    // Module packages to load (e.g. ModulePackageList::DBAL_PACKAGE); null loads every
    // installed package, an empty list loads Core (which includes Asynchronous) alone
    'enabledModulePackageNames' => null,

    // Enable test mode
    'test' => false,

    // Enterprise licence key
    'licenceKey' => null,
];
```

## All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `serviceName` | `null` | Service identifier for distributed messaging |
| `loadAppNamespaces` | `true` | Auto-scan `app/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `cacheConfiguration` | `false` | Cache messaging config (auto in prod) |
| `defaultSerializationMediaType` | `null` | Media type for async serialization |
| `defaultErrorChannel` | `null` | Error channel name |
| `defaultConnectionExceptionRetry` | `null` | Retry config for connection failures |
| `enabledModulePackageNames` | `null` (every installed package) | Explicit list of module packages to load, besides Core |
| `test` | `false` | Enable test mode |
| `licenceKey` | `null` | Enterprise licence key |

Any other key is refused at bootstrap with a `ConfigurationException` naming the keys Ecotone reads. The 1.x
`skippedModulePackageNames` key and the earlier 2.0 `modulePackages` key are refused with their replacement:
`skippedModulePackageNames` listed packages to skip, `enabledModulePackageNames` lists packages to load.

## LaravelConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultConnection(connectionName)` | Default connection using Laravel DB config |
| `create(connectionName, referenceName)` | Named connection with custom reference |
