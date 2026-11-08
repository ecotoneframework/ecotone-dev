# Laravel Configuration Reference

## Configuration File (config/ecotone.php)

```php
return [
    // Auto-load classes from app/ directory (default: true)
    'loadAppNamespaces' => true,

    // Additional namespaces to scan
    'namespaces' => [],

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
| `loadAppNamespaces` | `true` | Auto-scan `app/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `enabledModulePackageNames` | `null` (every installed package) | Explicit list of module packages to load, besides Core |
| `test` | `false` | Enable test mode |
| `licenceKey` | `null` | Enterprise licence key |

The service name, default serialization media type, default error channel, consumer memory limit, connection retry
template and headers in log context are not config keys. They are set on `ServiceConfiguration`, returned from exactly
one `#[ServiceContext]` method:

```php
#[ServiceContext]
public function serviceConfiguration(): ServiceConfiguration
{
    return ServiceConfiguration::createWithDefaults()
        ->withServiceName(env('ECOTONE_SERVICE_NAME'))
        ->withDefaultSerializationMediaType('application/json')
        ->withDefaultErrorChannel('errorChannel')
        ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackOff(100, 2)->maxRetries(3))
        ->withHeadersInLogContext(['userId']);
}
```

The container cache mode is derived: in `production` or once `config:cache` has run, the dumped container is reused
as is until `optimize:clear`, `cache:clear` or `config:clear`; otherwise it is rebuilt whenever a scanned file changes.

Any other key is refused at bootstrap with a `ConfigurationException` naming the keys Ecotone reads. The 1.x
`skippedModulePackageNames` key and the earlier 2.0 `modulePackages` key are refused with their replacement:
`skippedModulePackageNames` listed packages to skip, `enabledModulePackageNames` lists packages to load.

## LaravelConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultConnection(connectionName)` | Default connection using Laravel DB config |
| `create(connectionName, referenceName)` | Named connection with custom reference |
