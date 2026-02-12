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

    // Skip specific module packages
    'skippedModulePackageNames' => [],

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
| `skippedModulePackageNames` | `[]` | Module packages to skip |
| `test` | `false` | Enable test mode |
| `licenceKey` | `null` | Enterprise licence key |

## LaravelConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultConnection(connectionName)` | Default connection using Laravel DB config |
| `create(connectionName, referenceName)` | Named connection with custom reference |
