---
type: llm
---
PASS if `config/ecotone.php` only uses keys among `loadAppNamespaces`, `namespaces`, `enabledModulePackageNames`, `test` and `licenceKey`, and the service name and error channel are set via `ServiceConfiguration::createWithDefaults()->withServiceName('orders-service')->withDefaultErrorChannel('errorChannel')` returned from a `#[ServiceContext]` method. FAIL if keys such as `serviceName` or `defaultErrorChannel` are put into `config/ecotone.php`.
